<?php

declare(strict_types=1);

namespace Facebook\WebDriver\Cdp;

use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\UnsupportedOperationException;
use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\Remote\DriverCommand;
use Facebook\WebDriver\Remote\JsonWireCompat;
use Facebook\WebDriver\Remote\WebDriverCommand;
use Facebook\WebDriver\Remote\WebDriverResponse;
use Facebook\WebDriver\WebDriverCommandExecutor;

/**
 * Speaks the WebDriver command protocol on the outside and CDP on the inside.
 *
 * This is the single seam where chromedriver is replaced: everything above it -
 * RemoteWebDriver, RemoteWebElement, WebDriverWait, page objects - keeps working
 * unchanged, because php-webdriver funnels every interaction through
 * WebDriverCommandExecutor::execute().
 *
 * The reason for going direct is Runtime.enable. chromedriver issues it for
 * every executeScript call, and it makes V8 format console output through
 * Error.prepareStackTrace - an automation signal fingerprinting scripts read.
 * Nothing here ever sends it.
 */
class CdpCommandExecutor implements WebDriverCommandExecutor
{
    private const ELEMENT_IDENTIFIER = JsonWireCompat::WEB_DRIVER_ELEMENT_IDENTIFIER;

    private readonly CdpElementRegistry $elements;

    private ?CdpSession $session = null;

    /** @var array<string, CdpSession> targetId => session, for window switching */
    private array $windows = [];

    public function __construct(
        private readonly CdpClient $client,
        private readonly ?CdpBrowserProcess $process = null,
    ) {
        $this->elements = new CdpElementRegistry();
    }

    /**
     * @throws WebDriverException
     */
    public function execute(WebDriverCommand $command): WebDriverResponse
    {
        $name = $command->getName();
        $parameters = $command->getParameters();

        $response = new WebDriverResponse($command->getSessionID());
        $response->setStatus(0);
        $response->setValue($this->dispatch($name, $parameters));

        return $response;
    }

    public function getSession(): CdpSession
    {
        if ($this->session === null) {
            throw new WebDriverException('No active CDP session; NEW_SESSION has not been executed');
        }

        return $this->session;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function dispatch(string $name, array $parameters): mixed
    {
        return match ($name) {
            DriverCommand::NEW_SESSION => $this->newSession(),
            DriverCommand::QUIT => $this->quit(),
            DriverCommand::STATUS => ['ready' => true, 'message' => 'CDP executor ready'],
            DriverCommand::GET => $this->get($parameters),
            DriverCommand::GET_CURRENT_URL => $this->getSession()->getCurrentUrl(),
            DriverCommand::GET_TITLE => $this->scriptValue('document.title'),
            DriverCommand::GET_PAGE_SOURCE => $this->getPageSource(),
            DriverCommand::REFRESH => $this->reload(),
            DriverCommand::GO_BACK => $this->history(-1),
            DriverCommand::GO_FORWARD => $this->history(1),
            DriverCommand::EXECUTE_SCRIPT => $this->executeScript($parameters),
            DriverCommand::EXECUTE_ASYNC_SCRIPT => $this->executeAsyncScript($parameters),
            DriverCommand::FIND_ELEMENT => $this->findElement($parameters),
            DriverCommand::FIND_ELEMENTS => $this->findElements($parameters),
            DriverCommand::FIND_CHILD_ELEMENT => $this->findChildElement($parameters),
            DriverCommand::FIND_CHILD_ELEMENTS => $this->findChildElements($parameters),
            DriverCommand::GET_ACTIVE_ELEMENT => $this->getActiveElement(),
            DriverCommand::CLICK_ELEMENT => $this->clickElement($parameters),
            DriverCommand::SEND_KEYS_TO_ELEMENT => $this->sendKeys($parameters),
            DriverCommand::CLEAR_ELEMENT => $this->clearElement($parameters),
            DriverCommand::SUBMIT_ELEMENT => $this->submitElement($parameters),
            DriverCommand::GET_ELEMENT_TEXT => $this->elementText($parameters),
            DriverCommand::GET_ELEMENT_ATTRIBUTE => $this->elementAttribute($parameters),
            DriverCommand::GET_ELEMENT_PROPERTY => $this->elementProperty($parameters),
            DriverCommand::GET_ELEMENT_TAG_NAME => $this->elementTagName($parameters),
            DriverCommand::GET_ELEMENT_VALUE_OF_CSS_PROPERTY => $this->elementCssValue($parameters),
            DriverCommand::IS_ELEMENT_DISPLAYED => $this->isElementDisplayed($parameters),
            DriverCommand::IS_ELEMENT_ENABLED => $this->isElementEnabled($parameters),
            DriverCommand::IS_ELEMENT_SELECTED => $this->isElementSelected($parameters),
            DriverCommand::GET_ELEMENT_LOCATION,
            DriverCommand::GET_ELEMENT_LOCATION_ONCE_SCROLLED_INTO_VIEW => $this->elementLocation($parameters),
            DriverCommand::GET_ELEMENT_SIZE => $this->elementSize($parameters),
            DriverCommand::GET_ELEMENT_SHADOW_ROOT => $this->elementShadowRoot($parameters),
            DriverCommand::ELEMENT_EQUALS => $this->elementEquals($parameters),
            DriverCommand::SWITCH_TO_FRAME => $this->switchToFrame($parameters),
            DriverCommand::SWITCH_TO_PARENT_FRAME => $this->switchToParentFrame(),
            DriverCommand::GET_CURRENT_WINDOW_HANDLE => $this->getSession()->getTargetId(),
            DriverCommand::GET_WINDOW_HANDLES => $this->getWindowHandles(),
            DriverCommand::SWITCH_TO_WINDOW => $this->switchToWindow($parameters),
            DriverCommand::NEW_WINDOW => $this->newWindow(),
            DriverCommand::CLOSE => $this->closeWindow(),
            DriverCommand::SET_WINDOW_SIZE => $this->setWindowSize($parameters),
            DriverCommand::GET_WINDOW_SIZE => $this->getWindowSize(),
            DriverCommand::SET_WINDOW_POSITION => $this->setWindowPosition($parameters),
            DriverCommand::GET_WINDOW_POSITION => ['x' => 0, 'y' => 0],
            DriverCommand::MAXIMIZE_WINDOW, DriverCommand::FULLSCREEN_WINDOW => $this->getWindowSize(),
            DriverCommand::SCREENSHOT => $this->screenshot(),
            DriverCommand::GET_ALL_COOKIES => $this->getCookies(),
            DriverCommand::ADD_COOKIE => $this->addCookie($parameters),
            DriverCommand::DELETE_ALL_COOKIES => $this->deleteAllCookies(),
            DriverCommand::DELETE_COOKIE => $this->deleteCookie($parameters),
            DriverCommand::SET_TIMEOUT, DriverCommand::IMPLICITLY_WAIT => null,
            DriverCommand::ACTIONS => $this->performActions($parameters),
            DriverCommand::CUSTOM_COMMAND => $this->customCommand($parameters),
            default => throw new UnsupportedOperationException(sprintf(
                'Command "%s" is not implemented by the CDP executor. '
                . 'Add a mapping in %s::dispatch() if the driver needs it.',
                $name,
                self::class,
            )),
        };
    }

    // --- session lifecycle -------------------------------------------------

    /**
     * @return array<string, mixed> capabilities, as a W3C new-session reply
     */
    private function newSession(): array
    {
        $this->session = new CdpSession($this->client);
        $this->windows[$this->session->getTargetId()] = $this->session;

        $version = $this->client->send('Browser.getVersion');

        return [
            'browserName' => 'chrome',
            'browserVersion' => $version['product'] ?? '',
            'platformName' => PHP_OS_FAMILY,
            'se:cdp' => true,
        ];
    }

    private function quit(): null
    {
        foreach ($this->windows as $session) {
            $session->close();
        }

        $this->windows = [];
        $this->session = null;
        $this->elements->clear();

        $this->process?->stop();

        return null;
    }

    // --- navigation --------------------------------------------------------

    /**
     * @param array<string, mixed> $parameters
     */
    private function get(array $parameters): null
    {
        // Element handles from the previous document are dead after navigating.
        $this->elements->clear();
        $this->getSession()->navigate((string) $parameters['url']);

        return null;
    }

    private function reload(): null
    {
        $this->elements->clear();
        $session = $this->getSession();
        $session->getClient()->drainEvents('Page.loadEventFired', $session->getSessionId());
        $session->send('Page.reload');
        $session->awaitLoad();

        return null;
    }

    private function history(int $delta): null
    {
        $session = $this->getSession();
        $history = $session->send('Page.getNavigationHistory');
        $target = $history['currentIndex'] + $delta;

        if (!isset($history['entries'][$target])) {
            return null;
        }

        $this->elements->clear();
        $session->getClient()->drainEvents('Page.loadEventFired', $session->getSessionId());
        $session->send('Page.navigateToHistoryEntry', ['entryId' => $history['entries'][$target]['id']]);
        $session->awaitLoad();

        return null;
    }

    private function getPageSource(): string
    {
        $session = $this->getSession();
        $document = $session->send('DOM.getDocument', ['depth' => 0]);

        return (string) $session->send('DOM.getOuterHTML', [
            'nodeId' => $document['root']['nodeId'],
        ])['outerHTML'];
    }

    // --- scripts -----------------------------------------------------------

    /**
     * WebDriver passes the script body plus an argument list; CDP wants a single
     * callable, so we wrap the body in a function and hand the arguments over as
     * remote-object references where they are elements.
     *
     * @param array<string, mixed> $parameters
     */
    private function executeScript(array $parameters): mixed
    {
        $declaration = sprintf(
            'function () { return (function () { %s }).apply(this, arguments); }',
            (string) $parameters['script'],
        );

        return $this->callWithArguments($declaration, (array) ($parameters['args'] ?? []));
    }

    /**
     * Async scripts get a callback as their last argument and resolve when it
     * fires; we bridge that to a promise CDP can await.
     *
     * @param array<string, mixed> $parameters
     */
    private function executeAsyncScript(array $parameters): mixed
    {
        $declaration = sprintf(
            'function () {
                var args = Array.prototype.slice.call(arguments);

                return new Promise(function (resolve, reject) {
                    args.push(resolve);

                    try {
                        (function () { %s }).apply(null, args);
                    } catch (error) {
                        reject(error);
                    }
                });
            }',
            (string) $parameters['script'],
        );

        return $this->callWithArguments($declaration, (array) ($parameters['args'] ?? []));
    }

    /**
     * @param list<mixed> $args
     */
    private function callWithArguments(string $declaration, array $args): mixed
    {
        $session = $this->getSession();

        $callArguments = array_map(
            fn (mixed $argument): array => $this->encodeArgument($argument),
            array_values($args),
        );

        // Bind `this` to window so the wrapped body behaves like a page script.
        $windowObject = $session->evaluate('window', false);

        // Ask for a reference rather than a value: CDP serialises a DOM node to
        // an empty object under returnByValue, losing the objectId we need to
        // hand back an element. Plain values are unwrapped in decodeResult().
        $result = $session->callFunctionOn(
            $declaration,
            (string) $windowObject['objectId'],
            $callArguments,
            false,
        );

        $session->releaseObject((string) $windowObject['objectId']);

        return $this->decodeResult($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeArgument(mixed $argument): array
    {
        if (\is_array($argument) && isset($argument[self::ELEMENT_IDENTIFIER])) {
            return ['objectId' => $this->elements->resolve((string) $argument[self::ELEMENT_IDENTIFIER])];
        }

        return ['value' => $argument];
    }

    /**
     * Element results must go back to the client as WebDriver handles, not as
     * raw CDP objects.
     *
     * @param array<string, mixed> $result a CDP RemoteObject
     */
    private function decodeResult(array $result): mixed
    {
        if (($result['subtype'] ?? null) === 'node' && isset($result['objectId'])) {
            return [self::ELEMENT_IDENTIFIER => $this->elements->store((string) $result['objectId'])];
        }

        if (array_key_exists('value', $result)) {
            return $result['value'];
        }

        // A reference we asked not to serialise: pull the value out now, taking
        // care to keep any nested element handles intact.
        if (isset($result['objectId'])) {
            return $this->unwrapObject((string) $result['objectId'], (string) ($result['subtype'] ?? ''));
        }

        return null;
    }

    /**
     * Converts a remote object into a PHP value, mapping any elements it
     * contains to WebDriver handles.
     */
    private function unwrapObject(string $objectId, string $subtype): mixed
    {
        $session = $this->getSession();

        if ($subtype === 'array') {
            $properties = $session->send('Runtime.getProperties', [
                'objectId' => $objectId,
                'ownProperties' => true,
            ]);

            $items = [];
            foreach ($properties['result'] as $property) {
                if (!ctype_digit((string) $property['name'])) {
                    continue;
                }

                $items[(int) $property['name']] = $this->decodeResult((array) ($property['value'] ?? []));
            }

            ksort($items);
            $session->releaseObject($objectId);

            return array_values($items);
        }

        // Anything else (plain objects, null-like references) is safe to
        // serialise now that we know it is not a node.
        $value = $session->callFunctionOn('function () { return this; }', $objectId, [], true);
        $session->releaseObject($objectId);

        return $value['value'] ?? null;
    }

    private function scriptValue(string $expression): mixed
    {
        return $this->getSession()->evaluate($expression)['value'] ?? null;
    }

    // --- element lookup ----------------------------------------------------

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, string>
     */
    private function findElement(array $parameters): array
    {
        $objectId = $this->getSession()->querySelector($this->toCssSelector($parameters));

        return [self::ELEMENT_IDENTIFIER => $this->elements->store($objectId)];
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<array<string, string>>
     */
    private function findElements(array $parameters): array
    {
        return $this->wrapElements(
            $this->getSession()->querySelectorAll($this->toCssSelector($parameters)),
        );
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, string>
     */
    private function findChildElement(array $parameters): array
    {
        $objectId = $this->getSession()->querySelector(
            $this->toCssSelector($parameters),
            $this->objectIdFrom($parameters),
        );

        return [self::ELEMENT_IDENTIFIER => $this->elements->store($objectId)];
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<array<string, string>>
     */
    private function findChildElements(array $parameters): array
    {
        return $this->wrapElements($this->getSession()->querySelectorAll(
            $this->toCssSelector($parameters),
            $this->objectIdFrom($parameters),
        ));
    }

    /**
     * @return array<string, string>
     */
    private function getActiveElement(): array
    {
        $result = $this->getSession()->evaluate('document.activeElement', false);

        if (!isset($result['objectId'])) {
            throw new NoSuchElementException('There is no active element');
        }

        return [self::ELEMENT_IDENTIFIER => $this->elements->store((string) $result['objectId'])];
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, string>
     */
    private function elementShadowRoot(array $parameters): array
    {
        $result = $this->getSession()->callFunctionOn(
            'function () { return this.shadowRoot; }',
            $this->objectIdFrom($parameters),
            [],
            false,
        );

        if (!isset($result['objectId'])) {
            throw new NoSuchElementException('Element has no shadow root');
        }

        return [self::ELEMENT_IDENTIFIER => $this->elements->store((string) $result['objectId'])];
    }

    /**
     * php-webdriver normalises id/name/class-name selectors to CSS before they
     * reach the executor; anything left that is not CSS or XPath we cannot map.
     *
     * @param array<string, mixed> $parameters
     */
    private function toCssSelector(array $parameters): string
    {
        $using = (string) ($parameters['using'] ?? 'css selector');
        $value = (string) ($parameters['value'] ?? '');

        if ($using === 'css selector') {
            return $value;
        }

        throw new UnsupportedOperationException(sprintf(
            'Locator strategy "%s" is not supported by the CDP executor; use a CSS selector.',
            $using,
        ));
    }

    /**
     * @param list<string> $objectIds
     *
     * @return list<array<string, string>>
     */
    private function wrapElements(array $objectIds): array
    {
        return array_map(
            fn (string $objectId): array => [self::ELEMENT_IDENTIFIER => $this->elements->store($objectId)],
            $objectIds,
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function objectIdFrom(array $parameters): string
    {
        $elementId = (string) ($parameters[':id'] ?? $parameters['id'] ?? '');

        if ($elementId === '') {
            throw new WebDriverException('Command requires an element id but none was given');
        }

        return $this->elements->resolve($elementId);
    }

    // --- element state -----------------------------------------------------

    /**
     * @param array<string, mixed> $parameters
     */
    private function elementText(array $parameters): string
    {
        // innerText rather than textContent: WebDriver reports rendered text.
        return (string) ($this->callOnElement($parameters, 'function () { return this.innerText; }') ?? '');
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function elementAttribute(array $parameters): ?string
    {
        $value = $this->callOnElement(
            $parameters,
            'function (name) { return this.getAttribute(name); }',
            [['value' => $this->requiredName($parameters)]],
        );

        return $value === null ? null : (string) $value;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function elementProperty(array $parameters): mixed
    {
        return $this->callOnElement(
            $parameters,
            'function (name) { return this[name]; }',
            [['value' => $this->requiredName($parameters)]],
        );
    }

    /**
     * php-webdriver prefixes element command parameters with a colon.
     *
     * @param array<string, mixed> $parameters
     */
    private function requiredName(array $parameters): string
    {
        $name = $parameters[':name'] ?? $parameters['name'] ?? null;

        if ($name === null) {
            throw new WebDriverException('Command requires an attribute or property name');
        }

        return (string) $name;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function elementTagName(array $parameters): string
    {
        return (string) $this->callOnElement($parameters, 'function () { return this.tagName.toLowerCase(); }');
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function elementCssValue(array $parameters): string
    {
        return (string) $this->callOnElement(
            $parameters,
            'function (property) { return window.getComputedStyle(this).getPropertyValue(property); }',
            [['value' => (string) ($parameters[':propertyName'] ?? $parameters['property_name'] ?? '')]],
        );
    }

    /**
     * Mirrors the WebDriver notion of displayedness closely enough for page
     * objects: laid out, not hidden by styles, and not fully transparent.
     *
     * @param array<string, mixed> $parameters
     */
    private function isElementDisplayed(array $parameters): bool
    {
        return (bool) $this->callOnElement($parameters, 'function () {
            if (!this.isConnected) {
                return false;
            }

            var style = window.getComputedStyle(this);

            if (style.visibility === "hidden" || style.display === "none" || Number(style.opacity) === 0) {
                return false;
            }

            var rect = this.getBoundingClientRect();

            return rect.width > 0 && rect.height > 0;
        }');
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function isElementEnabled(array $parameters): bool
    {
        return (bool) $this->callOnElement($parameters, 'function () { return !this.disabled; }');
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function isElementSelected(array $parameters): bool
    {
        return (bool) $this->callOnElement($parameters, 'function () { return !!(this.checked || this.selected); }');
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array{x: int, y: int}
     */
    private function elementLocation(array $parameters): array
    {
        $rect = $this->boundingRect($parameters);

        return ['x' => (int) round($rect['x']), 'y' => (int) round($rect['y'])];
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array{width: int, height: int}
     */
    private function elementSize(array $parameters): array
    {
        $rect = $this->boundingRect($parameters);

        return ['width' => (int) round($rect['width']), 'height' => (int) round($rect['height'])];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function elementEquals(array $parameters): bool
    {
        $other = (string) ($parameters['other'] ?? '');

        return $this->objectIdFrom($parameters) === $this->elements->resolve($other);
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array{x: float, y: float, width: float, height: float}
     */
    private function boundingRect(array $parameters): array
    {
        $rect = $this->callOnElement($parameters, 'function () {
            var rect = this.getBoundingClientRect();

            return { x: rect.x, y: rect.y, width: rect.width, height: rect.height };
        }');

        return [
            'x' => (float) $rect['x'],
            'y' => (float) $rect['y'],
            'width' => (float) $rect['width'],
            'height' => (float) $rect['height'],
        ];
    }

    /**
     * @param array<string, mixed>       $parameters
     * @param list<array<string, mixed>> $arguments
     */
    private function callOnElement(array $parameters, string $declaration, array $arguments = []): mixed
    {
        $result = $this->getSession()->callFunctionOn(
            $declaration,
            $this->objectIdFrom($parameters),
            $arguments,
        );

        return $this->decodeResult($result);
    }

    // --- element interaction -----------------------------------------------

    /**
     * Clicks through Input.dispatchMouseEvent rather than element.click().
     *
     * A synthetic DOM click carries isTrusted=false and skips the pointer event
     * sequence a real user produces; dispatching at the browser level produces
     * genuine input the page cannot tell apart.
     *
     * @param array<string, mixed> $parameters
     */
    private function clickElement(array $parameters): null
    {
        $session = $this->getSession();

        $this->callOnElement($parameters, 'function () {
            this.scrollIntoView({ block: "center", inline: "center", behavior: "instant" });
        }');

        $rect = $this->boundingRect($parameters);

        if ($rect['width'] <= 0.0 || $rect['height'] <= 0.0) {
            throw new WebDriverException('Cannot click an element that has no size');
        }

        $x = $rect['x'] + $rect['width'] / 2;
        $y = $rect['y'] + $rect['height'] / 2;

        $session->send('Input.dispatchMouseEvent', [
            'type' => 'mouseMoved',
            'x' => $x,
            'y' => $y,
        ]);

        foreach (['mousePressed', 'mouseReleased'] as $type) {
            $session->send('Input.dispatchMouseEvent', [
                'type' => $type,
                'x' => $x,
                'y' => $y,
                'button' => 'left',
                'buttons' => $type === 'mousePressed' ? 1 : 0,
                'clickCount' => 1,
            ]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function sendKeys(array $parameters): null
    {
        $session = $this->getSession();

        $this->callOnElement($parameters, 'function () { this.focus(); }');

        foreach ($this->collectKeys($parameters) as $character) {
            $this->typeCharacter($session, $character);
        }

        return null;
    }

    /**
     * WebDriver may pass the text as a string, as a list of strings, or as the
     * legacy 'value' array of single characters.
     *
     * @param array<string, mixed> $parameters
     *
     * @return list<string>
     */
    private function collectKeys(array $parameters): array
    {
        $raw = $parameters['text'] ?? $parameters['value'] ?? '';
        $text = \is_array($raw) ? implode('', array_map('strval', $raw)) : (string) $raw;

        return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function typeCharacter(CdpSession $session, string $character): void
    {
        $special = CdpKeyMap::resolve($character);

        if ($special !== null) {
            foreach (['rawKeyDown', 'keyUp'] as $type) {
                $session->send('Input.dispatchKeyEvent', $special + ['type' => $type]);
            }

            return;
        }

        // A printable character needs keyDown/char/keyUp so both key handlers and
        // the input value see it.
        $session->send('Input.dispatchKeyEvent', [
            'type' => 'keyDown',
            'text' => $character,
            'unmodifiedText' => $character,
            'key' => $character,
        ]);

        $session->send('Input.dispatchKeyEvent', [
            'type' => 'keyUp',
            'key' => $character,
        ]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function clearElement(array $parameters): null
    {
        $this->callOnElement($parameters, 'function () {
            this.focus();

            if ("value" in this) {
                this.value = "";
            } else if (this.isContentEditable) {
                this.textContent = "";
            }

            this.dispatchEvent(new Event("input", { bubbles: true }));
            this.dispatchEvent(new Event("change", { bubbles: true }));
        }');

        return null;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function submitElement(array $parameters): null
    {
        $this->callOnElement($parameters, 'function () {
            var form = this.form || this.closest("form");

            if (form) {
                form.submit();
            }
        }');

        return null;
    }

    /**
     * Raw Input.dispatch* passthrough for the W3C actions API.
     *
     * @param array<string, mixed> $parameters
     */
    private function performActions(array $parameters): null
    {
        $session = $this->getSession();

        foreach ((array) ($parameters['actions'] ?? []) as $source) {
            foreach ((array) ($source['actions'] ?? []) as $action) {
                $this->performAction($session, (string) ($source['type'] ?? ''), (array) $action);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $action
     */
    private function performAction(CdpSession $session, string $sourceType, array $action): void
    {
        $type = (string) ($action['type'] ?? '');

        if ($type === 'pause') {
            usleep(((int) ($action['duration'] ?? 0)) * 1000);

            return;
        }

        if ($sourceType === 'pointer') {
            $session->send('Input.dispatchMouseEvent', [
                'type' => match ($type) {
                    'pointerDown' => 'mousePressed',
                    'pointerUp' => 'mouseReleased',
                    default => 'mouseMoved',
                },
                'x' => (float) ($action['x'] ?? 0),
                'y' => (float) ($action['y'] ?? 0),
                'button' => 'left',
                'clickCount' => $type === 'pointerMove' ? 0 : 1,
            ]);

            return;
        }

        if ($sourceType === 'key') {
            $this->typeCharacter($session, (string) ($action['value'] ?? ''));

            return;
        }

        throw new UnsupportedOperationException(sprintf('Action source "%s" is not supported', $sourceType));
    }

    // --- frames ------------------------------------------------------------

    /**
     * @param array<string, mixed> $parameters
     */
    private function switchToFrame(array $parameters): null
    {
        $session = $this->getSession();
        $id = $parameters['id'] ?? null;

        if ($id === null) {
            $session->setFrame(null);

            return null;
        }

        $frameObjectId = \is_array($id) && isset($id[self::ELEMENT_IDENTIFIER])
            ? $this->elements->resolve((string) $id[self::ELEMENT_IDENTIFIER])
            : $this->frameObjectIdByIndex((int) $id);

        $node = $session->send('DOM.describeNode', ['objectId' => $frameObjectId]);
        $frameId = $node['node']['frameId'] ?? null;

        if ($frameId === null) {
            throw new NoSuchElementException('Target element is not a frame');
        }

        $session->setFrame((string) $frameId);

        return null;
    }

    private function frameObjectIdByIndex(int $index): string
    {
        $result = $this->getSession()->evaluate(
            sprintf('document.querySelectorAll("iframe, frame")[%d]', $index),
            false,
        );

        if (!isset($result['objectId'])) {
            throw new NoSuchElementException(sprintf('No frame at index %d', $index));
        }

        return (string) $result['objectId'];
    }

    private function switchToParentFrame(): null
    {
        // Single-level nesting is all the driver uses; parent of any frame is top.
        $this->getSession()->setFrame(null);

        return null;
    }

    // --- windows -----------------------------------------------------------

    /**
     * @return list<string>
     */
    private function getWindowHandles(): array
    {
        $targets = $this->client->send('Target.getTargets');
        $handles = [];

        foreach ($targets['targetInfos'] as $info) {
            if (($info['type'] ?? '') === 'page') {
                $handles[] = (string) $info['targetId'];
            }
        }

        return $handles;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function switchToWindow(array $parameters): null
    {
        $handle = (string) ($parameters['handle'] ?? $parameters['name'] ?? '');

        if (!isset($this->windows[$handle])) {
            $this->windows[$handle] = new CdpSession($this->client, $handle);
        }

        $this->session = $this->windows[$handle];
        $this->elements->clear();

        return null;
    }

    /**
     * @return array{handle: string, type: string}
     */
    private function newWindow(): array
    {
        $session = new CdpSession($this->client);
        $this->windows[$session->getTargetId()] = $session;

        return ['handle' => $session->getTargetId(), 'type' => 'tab'];
    }

    private function closeWindow(): null
    {
        $session = $this->getSession();
        $handle = $session->getTargetId();

        $session->close();
        unset($this->windows[$handle]);
        $this->elements->clear();

        $this->session = $this->windows === [] ? null : reset($this->windows);

        return null;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function setWindowSize(array $parameters): null
    {
        $this->getSession()->send('Emulation.setDeviceMetricsOverride', [
            'width' => (int) ($parameters['width'] ?? 0),
            'height' => (int) ($parameters['height'] ?? 0),
            'deviceScaleFactor' => 0,
            'mobile' => false,
        ]);

        return null;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function setWindowPosition(array $parameters): null
    {
        $bounds = $this->client->send('Browser.getWindowForTarget', [
            'targetId' => $this->getSession()->getTargetId(),
        ]);

        $this->client->send('Browser.setWindowBounds', [
            'windowId' => $bounds['windowId'],
            'bounds' => [
                'left' => (int) ($parameters['x'] ?? 0),
                'top' => (int) ($parameters['y'] ?? 0),
            ],
        ]);

        return null;
    }

    /**
     * @return array{width: int, height: int}
     */
    private function getWindowSize(): array
    {
        $metrics = $this->getSession()->send('Page.getLayoutMetrics');

        return [
            'width' => (int) $metrics['cssLayoutViewport']['clientWidth'],
            'height' => (int) $metrics['cssLayoutViewport']['clientHeight'],
        ];
    }

    private function screenshot(): string
    {
        return (string) $this->getSession()->send('Page.captureScreenshot', ['format' => 'png'])['data'];
    }

    // --- cookies -----------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function getCookies(): array
    {
        return array_values((array) $this->getSession()->send('Network.getCookies')['cookies']);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function addCookie(array $parameters): null
    {
        $cookie = (array) ($parameters['cookie'] ?? $parameters);
        $cookie['url'] ??= $this->getSession()->getCurrentUrl();

        $this->getSession()->send('Network.setCookie', array_filter(
            $cookie,
            static fn (mixed $value): bool => $value !== null,
        ));

        return null;
    }

    private function deleteAllCookies(): null
    {
        $this->getSession()->send('Network.clearBrowserCookies');

        return null;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function deleteCookie(array $parameters): null
    {
        $this->getSession()->send('Network.deleteCookies', [
            'name' => (string) ($parameters['name'] ?? ''),
            'url' => $this->getSession()->getCurrentUrl(),
        ]);

        return null;
    }

    /**
     * Lets callers send CDP commands straight through, which is how stealth
     * scripts reach Page.addScriptToEvaluateOnNewDocument.
     *
     * @param array<string, mixed> $parameters
     */
    private function customCommand(array $parameters): mixed
    {
        $method = (string) ($parameters['cmd'] ?? '');

        if ($method === '') {
            throw new WebDriverException('Custom command requires a "cmd" parameter naming a CDP method');
        }

        return $this->getSession()->send($method, (array) ($parameters['params'] ?? []));
    }
}
