<?php

declare(strict_types=1);

namespace Facebook\WebDriver\Cdp;

use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\WebDriverException;

/**
 * One browser tab, driven over CDP.
 *
 * Deliberately never sends Runtime.enable: that command makes V8 route console
 * output through Error.prepareStackTrace, which fingerprinting scripts read as
 * an automation signal. Runtime.evaluate without an explicit contextId already
 * runs in the page's main world, so the command buys us nothing anyway.
 */
class CdpSession
{
    private const NAVIGATION_TIMEOUT = 30.0;

    private string $sessionId;

    private string $targetId;

    /** Frame the driver currently addresses; null means the top-level document. */
    private ?string $currentFrameId = null;

    /**
     * Network events seen so far, in chromedriver's performance-log shape.
     *
     * @var list<array{message: string}>
     */
    private array $performanceLog = [];

    public function __construct(private readonly CdpClient $client, ?string $targetId = null)
    {
        if ($targetId === null) {
            $created = $this->client->send('Target.createTarget', ['url' => 'about:blank']);
            $targetId = (string) $created['targetId'];
        }

        $this->targetId = $targetId;

        $attached = $this->client->send('Target.attachToTarget', [
            'targetId' => $this->targetId,
            'flatten' => true,
        ]);

        $this->sessionId = (string) $attached['sessionId'];

        $this->send('Page.enable');
        $this->send('DOM.enable');
        $this->send('Network.enable');
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getClient(): CdpClient
    {
        return $this->client;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function send(string $method, array $params = []): array
    {
        return $this->client->send($method, $params, $this->sessionId);
    }

    public function navigate(string $url): void
    {
        // A load event buffered from the previous navigation would satisfy the
        // wait below immediately, so drop stale ones before asking for a new page.
        $this->client->drainEvents('Page.loadEventFired', $this->sessionId);

        $result = $this->send('Page.navigate', ['url' => $url]);

        if (isset($result['errorText'])) {
            throw new WebDriverException(sprintf('Navigation to %s failed: %s', $url, $result['errorText']));
        }

        $this->awaitLoad();
        $this->currentFrameId = null;
    }

    public function awaitLoad(float $timeout = self::NAVIGATION_TIMEOUT): void
    {
        $this->client->waitForEvent('Page.loadEventFired', $timeout, $this->sessionId);
        $this->collectPerformanceEvents();
    }

    /**
     * Drains buffered network events in the shape chromedriver's performance log
     * uses: one entry per event, the CDP message JSON-encoded under 'message'.
     *
     * @return list<array{message: string, level: string, timestamp: int}>
     */
    public function takePerformanceLog(): array
    {
        $this->collectPerformanceEvents();

        $entries = $this->performanceLog;
        $this->performanceLog = [];

        return $entries;
    }

    private function collectPerformanceEvents(): void
    {
        // Pick up anything the browser pushed since the last command, then take
        // only Network events - Page events are still needed by awaitLoad().
        $this->client->pump();

        foreach ($this->client->drainEventsByPrefix('Network.', $this->sessionId) as $event) {
            $method = (string) ($event['method'] ?? '');

            $this->performanceLog[] = [
                'message' => json_encode([
                    'message' => [
                        'method' => $method,
                        'params' => $event['params'] ?? [],
                    ],
                    'webview' => $this->targetId,
                ], JSON_THROW_ON_ERROR),
                'level' => 'INFO',
                'timestamp' => (int) (microtime(true) * 1000),
            ];
        }
    }

    public function getCurrentUrl(): string
    {
        $info = $this->client->send('Target.getTargetInfo', ['targetId' => $this->targetId]);

        return (string) $info['targetInfo']['url'];
    }

    public function setFrame(?string $frameId): void
    {
        $this->currentFrameId = $frameId;
    }

    public function getFrameId(): ?string
    {
        return $this->currentFrameId;
    }

    public function getRootFrameId(): string
    {
        return (string) $this->send('Page.getFrameTree')['frameTree']['frame']['id'];
    }

    /**
     * Evaluates an expression in the page's main world.
     *
     * With no frame selected we pass no contextId at all - CDP then uses the
     * target's default context, which is the main world, without us having had
     * to enable the Runtime domain to learn its id.
     *
     * @return array<string, mixed> the raw CDP RemoteObject
     */
    public function evaluate(string $expression, bool $returnByValue = true, bool $awaitPromise = true): array
    {
        $params = [
            'expression' => $expression,
            'returnByValue' => $returnByValue,
            'awaitPromise' => $awaitPromise,
        ];

        $contextId = $this->resolveFrameContextId();
        if ($contextId !== null) {
            $params['contextId'] = $contextId;
        }

        $result = $this->send('Runtime.evaluate', $params);
        $this->assertNoException($result, $expression);

        return $result['result'];
    }

    /**
     * Calls a function with `this` bound to an existing remote object.
     *
     * @param list<array<string, mixed>> $arguments
     *
     * @return array<string, mixed> the raw CDP RemoteObject
     */
    public function callFunctionOn(
        string $declaration,
        string $objectId,
        array $arguments = [],
        bool $returnByValue = true,
    ): array {
        $result = $this->send('Runtime.callFunctionOn', [
            'functionDeclaration' => $declaration,
            'objectId' => $objectId,
            'arguments' => $arguments,
            'returnByValue' => $returnByValue,
            'awaitPromise' => true,
        ]);

        $this->assertNoException($result, $declaration);

        return $result['result'];
    }

    /**
     * Finds one node and returns its CDP object id.
     *
     * @throws NoSuchElementException
     */
    public function querySelector(string $selector, ?string $contextObjectId = null): string
    {
        $declaration = 'function (selector) { return this.querySelector(selector); }';

        $result = $contextObjectId === null
            ? $this->evaluateSelector($selector)
            : $this->callFunctionOn(
                $declaration,
                $contextObjectId,
                [['value' => $selector]],
                false,
            );

        if (!isset($result['objectId'])) {
            throw new NoSuchElementException(sprintf('No such element: %s', $selector));
        }

        return (string) $result['objectId'];
    }

    /**
     * XPath counterpart of querySelector().
     *
     * document.evaluate resolves a relative expression against its context node,
     * which is what WebDriver expects when searching inside an element.
     *
     * @throws NoSuchElementException
     */
    public function queryXPath(string $expression, ?string $contextObjectId = null): string
    {
        $declaration = 'function (expression) {
            var result = document.evaluate(
                expression,
                this,
                null,
                XPathResult.FIRST_ORDERED_NODE_TYPE,
                null
            );

            return result.singleNodeValue;
        }';

        $result = $this->callFunctionOn(
            $declaration,
            $contextObjectId ?? $this->getDocumentObjectId(),
            [['value' => $expression]],
            false,
        );

        if (!isset($result['objectId'])) {
            throw new NoSuchElementException(sprintf('No such element by xpath: %s', $expression));
        }

        return (string) $result['objectId'];
    }

    /**
     * @return list<string> CDP object ids
     */
    public function queryXPathAll(string $expression, ?string $contextObjectId = null): array
    {
        $declaration = 'function (expression) {
            var snapshot = document.evaluate(
                expression,
                this,
                null,
                XPathResult.ORDERED_NODE_SNAPSHOT_TYPE,
                null
            );

            var nodes = [];

            for (var i = 0; i < snapshot.snapshotLength; i++) {
                nodes.push(snapshot.snapshotItem(i));
            }

            return nodes;
        }';

        $arrayObject = $this->callFunctionOn(
            $declaration,
            $contextObjectId ?? $this->getDocumentObjectId(),
            [['value' => $expression]],
            false,
        );

        if (!isset($arrayObject['objectId'])) {
            return [];
        }

        return $this->collectArrayObjectIds((string) $arrayObject['objectId']);
    }

    /**
     * Document handle to evaluate absolute expressions against.
     */
    private function getDocumentObjectId(): string
    {
        $document = $this->evaluate('document', false);

        if (!isset($document['objectId'])) {
            throw new WebDriverException('Cannot obtain a handle on the document');
        }

        return (string) $document['objectId'];
    }

    /**
     * @return list<string> CDP object ids
     */
    public function querySelectorAll(string $selector, ?string $contextObjectId = null): array
    {
        $declaration = 'function (selector) { return Array.from(this.querySelectorAll(selector)); }';

        $arrayObject = $contextObjectId === null
            ? $this->evaluate(
                sprintf('Array.from(document.querySelectorAll(%s))', json_encode($selector, JSON_THROW_ON_ERROR)),
                false,
            )
            : $this->callFunctionOn($declaration, $contextObjectId, [['value' => $selector]], false);

        if (!isset($arrayObject['objectId'])) {
            return [];
        }

        return $this->collectArrayObjectIds((string) $arrayObject['objectId']);
    }

    /**
     * Reads the element handles out of a remote array and releases it.
     *
     * @return list<string>
     */
    private function collectArrayObjectIds(string $arrayObjectId): array
    {
        $properties = $this->send('Runtime.getProperties', [
            'objectId' => $arrayObjectId,
            'ownProperties' => true,
        ]);

        $objectIds = [];
        foreach ($properties['result'] as $property) {
            // Skip 'length' and any other non-indexed own property.
            if (!ctype_digit((string) $property['name'])) {
                continue;
            }

            if (isset($property['value']['objectId'])) {
                $objectIds[] = (string) $property['value']['objectId'];
            }
        }

        $this->releaseObject($arrayObjectId);

        return $objectIds;
    }

    public function releaseObject(string $objectId): void
    {
        try {
            $this->send('Runtime.releaseObject', ['objectId' => $objectId]);
        } catch (WebDriverException) {
            // The object may already be gone after a navigation - not worth failing over.
        }
    }

    public function close(): void
    {
        try {
            $this->client->send('Target.closeTarget', ['targetId' => $this->targetId]);
        } catch (WebDriverException) {
            // Target may already be closed.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluateSelector(string $selector): array
    {
        return $this->evaluate(
            sprintf('document.querySelector(%s)', json_encode($selector, JSON_THROW_ON_ERROR)),
            false,
        );
    }

    /**
     * An isolated world would break page scripts' expectations, so for frames we
     * ask CDP for the frame's own main-world context instead.
     */
    private function resolveFrameContextId(): ?int
    {
        if ($this->currentFrameId === null) {
            return null;
        }

        // Page.createIsolatedWorld is the only way to get a context id for a
        // specific frame without Runtime.enable; we keep universal access so the
        // handle still sees the frame's DOM.
        $world = $this->send('Page.createIsolatedWorld', [
            'frameId' => $this->currentFrameId,
            'worldName' => 'webdriver',
            'grantUniveralAccess' => true,
        ]);

        return (int) $world['executionContextId'];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function assertNoException(array $result, string $source): void
    {
        if (!isset($result['exceptionDetails'])) {
            return;
        }

        $details = $result['exceptionDetails'];
        $message = $details['exception']['description']
            ?? $details['text']
            ?? 'Unknown JavaScript error';

        throw new WebDriverException(sprintf('JavaScript error: %s (while evaluating %s)', $message, $source));
    }
}
