<?php

declare(strict_types=1);

namespace Facebook\WebDriver\Cdp;

use Facebook\WebDriver\Exception\WebDriverException;

/**
 * Minimal Chrome DevTools Protocol client speaking WebSocket over raw PHP
 * sockets - no composer dependencies, no chromedriver.
 *
 * The point of talking CDP directly is control over which commands are sent:
 * Runtime.enable makes V8 format console output through Error.prepareStackTrace,
 * which detectors use as an automation signal. This client never sends it.
 */
class CdpClient
{
    private const OPCODE_TEXT = 0x1;
    private const OPCODE_CLOSE = 0x8;
    private const OPCODE_PING = 0x9;
    private const OPCODE_PONG = 0xA;

    /** @var resource */
    private $socket;

    private int $messageId = 0;

    /** @var list<array<string, mixed>> */
    private array $pendingEvents = [];

    public function __construct(
        string $webSocketUrl,
        private readonly float $timeout = 10.0,
        private readonly ?string $connectHost = null,
        private readonly ?int $connectPort = null,
    )
    {
        $parts = parse_url($webSocketUrl);
        if ($parts === false || !isset($parts['host'], $parts['port'])) {
            throw new WebDriverException(sprintf('Malformed websocket url: %s', $webSocketUrl));
        }

        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $dialHost = $this->connectHost ?? $parts['host'];
        $dialPort = $this->connectPort ?? (int) $parts['port'];

        $errorCode = 0;
        $errorMessage = '';
        $socket = stream_socket_client(
            sprintf('tcp://%s:%d', $dialHost, $dialPort),
            $errorCode,
            $errorMessage,
            $this->timeout,
        );

        if ($socket === false) {
            throw new WebDriverException(sprintf('Cannot connect to %s: %s (%d)', $webSocketUrl, $errorMessage, $errorCode));
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, (int) $this->timeout);

        // Host header must keep Chrome's own origin, not the relay address.
        $this->handshake($parts['host'], (int) $parts['port'], $path);
    }

    public function __destruct()
    {
        if (is_resource($this->socket)) {
            @fwrite($this->socket, $this->encodeFrame('', self::OPCODE_CLOSE));
            @fclose($this->socket);
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function send(string $method, array $params = [], ?string $sessionId = null): array
    {
        $id = ++$this->messageId;

        $message = ['id' => $id, 'method' => $method];
        if ($params !== []) {
            $message['params'] = $params;
        }
        if ($sessionId !== null) {
            $message['sessionId'] = $sessionId;
        }

        $this->writeFrame(json_encode($message, JSON_THROW_ON_ERROR));

        return $this->awaitResponse($id);
    }

    /**
     * Pulls buffered events, optionally filtered by method name.
     *
     * @return list<array<string, mixed>>
     */
    public function drainEvents(?string $method = null, ?string $sessionId = null): array
    {
        if ($method === null && $sessionId === null) {
            $events = $this->pendingEvents;
            $this->pendingEvents = [];

            return $events;
        }

        $matched = [];
        $rest = [];
        foreach ($this->pendingEvents as $event) {
            if ($this->eventMatches($event, $method, $sessionId)) {
                $matched[] = $event;
            } else {
                $rest[] = $event;
            }
        }
        $this->pendingEvents = $rest;

        return $matched;
    }

    /**
     * Pulls buffered events whose method starts with the given prefix, leaving
     * everything else in the buffer.
     *
     * @return list<array<string, mixed>>
     */
    public function drainEventsByPrefix(string $prefix, ?string $sessionId = null): array
    {
        $matched = [];
        $rest = [];

        foreach ($this->pendingEvents as $event) {
            $isMatch = isset($event['method'])
                && str_starts_with((string) $event['method'], $prefix)
                && ($sessionId === null || ($event['sessionId'] ?? null) === $sessionId);

            if ($isMatch) {
                $matched[] = $event;
            } else {
                $rest[] = $event;
            }
        }

        $this->pendingEvents = $rest;

        return $matched;
    }

    /**
     * Reads whatever frames are already waiting on the socket without blocking,
     * so buffered events reflect what the browser has sent so far.
     */
    public function pump(float $seconds = 0.05): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            $frame = $this->readFrame(max(0.01, $deadline - microtime(true)));

            if ($frame === null) {
                return;
            }

            $decoded = json_decode($frame, true, 512, JSON_THROW_ON_ERROR);

            if (isset($decoded['method'])) {
                $this->pendingEvents[] = $decoded;
            }
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function eventMatches(array $event, ?string $method, ?string $sessionId): bool
    {
        if (!isset($event['method'])) {
            return false;
        }

        if ($method !== null && $event['method'] !== $method) {
            return false;
        }

        // A flat-mode session only cares about its own target's events.
        return $sessionId === null || ($event['sessionId'] ?? null) === $sessionId;
    }

    /**
     * Blocks until an event with the given method arrives, or the deadline passes.
     *
     * @return array<string, mixed>|null
     */
    public function waitForEvent(string $method, float $seconds = 5.0, ?string $sessionId = null): ?array
    {
        $buffered = $this->drainEvents($method, $sessionId);
        if ($buffered !== []) {
            return $buffered[0];
        }

        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $frame = $this->readFrame(max(0.1, $deadline - microtime(true)));
            if ($frame === null) {
                continue;
            }

            $decoded = json_decode($frame, true, 512, JSON_THROW_ON_ERROR);
            if ($this->eventMatches($decoded, $method, $sessionId)) {
                return $decoded;
            }
            if (isset($decoded['method'])) {
                $this->pendingEvents[] = $decoded;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function awaitResponse(int $id): array
    {
        $deadline = microtime(true) + $this->timeout;

        while (microtime(true) < $deadline) {
            $frame = $this->readFrame(max(0.1, $deadline - microtime(true)));
            if ($frame === null) {
                continue;
            }

            $decoded = json_decode($frame, true, 512, JSON_THROW_ON_ERROR);

            if (($decoded['id'] ?? null) === $id) {
                if (isset($decoded['error'])) {
                    throw new WebDriverException(sprintf(
                        'CDP error %s: %s',
                        (string) ($decoded['error']['code'] ?? '?'),
                        (string) ($decoded['error']['message'] ?? 'unknown'),
                    ));
                }

                return $decoded['result'] ?? [];
            }

            if (isset($decoded['method'])) {
                $this->pendingEvents[] = $decoded;
            }
        }

        throw new WebDriverException(sprintf('Timed out waiting for CDP response #%d', $id));
    }

    private function handshake(string $host, int $port, string $path): void
    {
        $key = base64_encode(random_bytes(16));

        $request = implode("\r\n", [
            sprintf('GET %s HTTP/1.1', $path),
            sprintf('Host: %s:%d', $host, $port),
            'Upgrade: websocket',
            'Connection: Upgrade',
            sprintf('Sec-WebSocket-Key: %s', $key),
            'Sec-WebSocket-Version: 13',
            '',
            '',
        ]);

        fwrite($this->socket, $request);

        $response = '';
        $deadline = microtime(true) + $this->timeout;
        while (!str_contains($response, "\r\n\r\n")) {
            if (microtime(true) > $deadline) {
                throw new WebDriverException('Timed out during websocket handshake');
            }
            $chunk = fgets($this->socket, 1024);
            if ($chunk === false) {
                throw new WebDriverException('Connection closed during websocket handshake');
            }
            $response .= $chunk;
        }

        if (!str_contains($response, ' 101 ')) {
            throw new WebDriverException(sprintf('Websocket upgrade refused: %s', strtok($response, "\r\n")));
        }

        $expected = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!str_contains($response, $expected)) {
            throw new WebDriverException('Websocket accept key mismatch');
        }
    }

    private function writeFrame(string $payload): void
    {
        $frame = $this->encodeFrame($payload, self::OPCODE_TEXT);

        $written = 0;
        $length = strlen($frame);
        while ($written < $length) {
            $bytes = fwrite($this->socket, substr($frame, $written));
            if ($bytes === false || $bytes === 0) {
                throw new WebDriverException('Failed writing to CDP socket');
            }
            $written += $bytes;
        }
    }

    /**
     * Client frames must be masked per RFC 6455.
     */
    private function encodeFrame(string $payload, int $opcode): string
    {
        $length = strlen($payload);
        $frame = chr(0x80 | $opcode);

        if ($length <= 125) {
            $frame .= chr(0x80 | $length);
        } elseif ($length <= 65535) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $length);
        }

        $mask = random_bytes(4);
        $frame .= $mask;

        for ($i = 0; $i < $length; ++$i) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }

        return $frame;
    }

    /**
     * Reads one complete (possibly fragmented) text frame. Returns null on timeout.
     */
    private function readFrame(float $seconds): ?string
    {
        $payload = '';

        while (true) {
            $header = $this->readBytes(2, $seconds);
            if ($header === null) {
                return null;
            }

            $firstByte = ord($header[0]);
            $secondByte = ord($header[1]);

            $isFinal = ($firstByte & 0x80) !== 0;
            $opcode = $firstByte & 0x0F;
            $isMasked = ($secondByte & 0x80) !== 0;
            $length = $secondByte & 0x7F;

            if ($length === 126) {
                $extended = $this->readBytes(2, $seconds);
                if ($extended === null) {
                    return null;
                }
                $length = unpack('n', $extended)[1];
            } elseif ($length === 127) {
                $extended = $this->readBytes(8, $seconds);
                if ($extended === null) {
                    return null;
                }
                $length = unpack('J', $extended)[1];
            }

            $mask = '';
            if ($isMasked) {
                $mask = $this->readBytes(4, $seconds);
                if ($mask === null) {
                    return null;
                }
            }

            $data = $length > 0 ? $this->readBytes($length, $seconds) : '';
            if ($data === null) {
                return null;
            }

            if ($isMasked && $data !== '') {
                for ($i = 0, $len = strlen($data); $i < $len; ++$i) {
                    $data[$i] = $data[$i] ^ $mask[$i % 4];
                }
            }

            if ($opcode === self::OPCODE_CLOSE) {
                throw new WebDriverException('CDP socket closed by remote peer');
            }

            if ($opcode === self::OPCODE_PING) {
                fwrite($this->socket, $this->encodeFrame($data, self::OPCODE_PONG));
                continue;
            }

            if ($opcode === self::OPCODE_PONG) {
                continue;
            }

            $payload .= $data;

            if ($isFinal) {
                return $payload;
            }
        }
    }

    private function readBytes(int $count, float $seconds): ?string
    {
        $buffer = '';
        $deadline = microtime(true) + $seconds;

        while (strlen($buffer) < $count) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }

            $read = [$this->socket];
            $write = null;
            $except = null;
            $ready = stream_select($read, $write, $except, (int) $remaining, (int) (fmod($remaining, 1) * 1_000_000));

            if ($ready === false) {
                throw new WebDriverException('stream_select failed on CDP socket');
            }
            if ($ready === 0) {
                return null;
            }

            $chunk = fread($this->socket, $count - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if ($meta['eof']) {
                    throw new WebDriverException('CDP socket reached EOF');
                }
                continue;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}
