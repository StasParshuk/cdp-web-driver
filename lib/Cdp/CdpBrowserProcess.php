<?php

declare(strict_types=1);

namespace Facebook\WebDriver\Cdp;

use Facebook\WebDriver\Exception\WebDriverException;

/**
 * Owns the Chrome process when there is no chromedriver to own it.
 *
 * Chrome is started with --remote-debugging-port=0 and reports the port it
 * actually picked in the DevToolsActivePort file inside the user data dir;
 * binding to a fixed port instead invites collisions between concurrent bots.
 */
class CdpBrowserProcess
{
    private const PORT_FILE = 'DevToolsActivePort';

    /** @var resource|null */
    private $process;

    private ?int $port = null;

    private ?string $userDataDir = null;

    /**
     * @param list<string> $arguments
     */
    public function __construct(
        private readonly string $binary = '/usr/bin/chromium',
        private readonly array $arguments = [],
        private readonly float $startupTimeout = 30.0,
    ) {
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function start(): void
    {
        if ($this->process !== null) {
            throw new WebDriverException('Browser process is already running');
        }

        $this->userDataDir = sprintf('%s/cdp-profile-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        if (!mkdir($this->userDataDir, 0700, true) && !is_dir($this->userDataDir)) {
            throw new WebDriverException(sprintf('Cannot create user data dir %s', $this->userDataDir));
        }

        $command = sprintf(
            '%s --remote-debugging-port=0 --user-data-dir=%s %s about:blank',
            escapeshellcmd($this->binary),
            escapeshellarg($this->userDataDir),
            implode(' ', array_map('escapeshellarg', $this->arguments)),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', sprintf('%s/chrome.log', $this->userDataDir), 'a'],
        ];

        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes);

        if (!\is_resource($process)) {
            throw new WebDriverException(sprintf('Cannot start browser: %s', $command));
        }

        $this->process = $process;
        $this->port = $this->awaitDevToolsPort();
    }

    public function getPort(): int
    {
        if ($this->port === null) {
            throw new WebDriverException('Browser process is not running');
        }

        return $this->port;
    }

    public function isRunning(): bool
    {
        if ($this->process === null) {
            return false;
        }

        $status = proc_get_status($this->process);

        return $status['running'] ?? false;
    }

    public function getWebSocketDebuggerUrl(): string
    {
        $endpoint = sprintf('http://127.0.0.1:%d/json/version', $this->getPort());

        $payload = @file_get_contents($endpoint);
        if ($payload === false) {
            throw new WebDriverException(sprintf('Cannot read CDP version endpoint %s', $endpoint));
        }

        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($decoded['webSocketDebuggerUrl'])) {
            throw new WebDriverException('CDP version endpoint returned no webSocketDebuggerUrl');
        }

        return (string) $decoded['webSocketDebuggerUrl'];
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            // SIGTERM lets Chrome flush its profile; killing it outright corrupts
            // the user data dir and the next start complains about it.
            proc_terminate($this->process, 15);

            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline && $this->isRunning()) {
                usleep(100_000);
            }

            if ($this->isRunning()) {
                proc_terminate($this->process, 9);
            }

            proc_close($this->process);
            $this->process = null;
        }

        if ($this->userDataDir !== null && is_dir($this->userDataDir)) {
            $this->removeDirectory($this->userDataDir);
            $this->userDataDir = null;
        }

        $this->port = null;
    }

    /**
     * Chrome writes the chosen port on the first line of DevToolsActivePort as
     * soon as the browser endpoint is listening.
     */
    private function awaitDevToolsPort(): int
    {
        $portFile = sprintf('%s/%s', $this->userDataDir, self::PORT_FILE);
        $deadline = microtime(true) + $this->startupTimeout;

        while (microtime(true) < $deadline) {
            if (!$this->isRunning()) {
                throw new WebDriverException(sprintf(
                    'Browser exited during startup; see %s/chrome.log',
                    (string) $this->userDataDir,
                ));
            }

            if (is_file($portFile)) {
                $contents = (string) @file_get_contents($portFile);
                $firstLine = strtok($contents, "\n");

                if ($firstLine !== false && ctype_digit(trim($firstLine))) {
                    return (int) trim($firstLine);
                }
            }

            usleep(100_000);
        }

        throw new WebDriverException(sprintf(
            'Timed out after %.1fs waiting for %s',
            $this->startupTimeout,
            $portFile,
        ));
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($directory);
    }
}
