<?php

declare(strict_types=1);

namespace Facebook\WebDriver\Cdp;

use Facebook\WebDriver\Exception\StaleElementReferenceException;

/**
 * Maps WebDriver element handles onto CDP RemoteObject ids.
 *
 * WebDriver hands the client an opaque element id and expects it to stay valid
 * until the node goes away; CDP instead works with objectIds that are scoped to
 * an execution context and silently die on navigation. This registry keeps the
 * translation in one place so the executor can deal in WebDriver handles.
 */
class CdpElementRegistry
{
    /** @var array<string, string> WebDriver element id => CDP object id */
    private array $objectIds = [];

    private int $sequence = 0;

    /**
     * Registers a CDP object id and returns the WebDriver handle for it.
     */
    public function store(string $objectId): string
    {
        $elementId = sprintf('cdp-element-%d', ++$this->sequence);
        $this->objectIds[$elementId] = $objectId;

        return $elementId;
    }

    /**
     * @throws StaleElementReferenceException when the handle is unknown - which
     *                                        is what WebDriver clients expect for a dead element.
     */
    public function resolve(string $elementId): string
    {
        if (!isset($this->objectIds[$elementId])) {
            throw new StaleElementReferenceException(
                sprintf('Element %s is no longer attached to the page', $elementId)
            );
        }

        return $this->objectIds[$elementId];
    }

    public function forget(string $elementId): void
    {
        unset($this->objectIds[$elementId]);
    }

    /**
     * Drops every handle. Called on navigation, when all objectIds become invalid.
     */
    public function clear(): void
    {
        $this->objectIds = [];
    }
}
