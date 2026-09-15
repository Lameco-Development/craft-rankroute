<?php

namespace lameco\rankroute\services;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use lameco\rankroute\services\fieldhandlers\FieldHandlerInterface;
use yii\base\InvalidConfigException;

/**
 * Field Handler Registry Service
 *
 * Manages registration and retrieval of field handlers for export/import operations.
 * Handlers are stored by priority and the first matching handler is used for each field type.
 */
class FieldHandlerRegistry extends Component
{
    /**
     * @var FieldHandlerInterface[] Registered field handlers
     */
    private array $handlers = [];

    /**
     * @var bool Whether handlers have been sorted by priority
     */
    private bool $sorted = false;

    /**
     * @var array Cache of field class => handler mappings for performance
     */
    private array $handlerCache = [];

    /**
     * Register a field handler
     *
     * @param FieldHandlerInterface $handler The handler to register
     * @return self For method chaining
     */
    public function register(FieldHandlerInterface $handler): self
    {
        $this->handlers[] = $handler;
        $this->sorted = false;
        $this->handlerCache = []; // Clear cache when new handler is added

        Craft::debug(
            'Registered field handler: ' . get_class($handler),
            __METHOD__
        );

        return $this;
    }

    /**
     * Register multiple field handlers at once
     *
     * @param array $handlers Array of FieldHandlerInterface instances
     * @return self For method chaining
     */
    public function registerMultiple(array $handlers): self
    {
        foreach ($handlers as $handler) {
            if (!$handler instanceof FieldHandlerInterface) {
                throw new InvalidConfigException(
                    'All handlers must implement FieldHandlerInterface'
                );
            }
            $this->register($handler);
        }

        return $this;
    }

    /**
     * Get the appropriate handler for a field
     *
     * @param FieldInterface $field The field to get a handler for
     * @return FieldHandlerInterface The matching handler
     * @throws InvalidConfigException If no handler can handle the field
     */
    public function getHandler(FieldInterface $field): FieldHandlerInterface
    {
        $fieldClass = get_class($field);

        // Check cache first
        if (isset($this->handlerCache[$fieldClass])) {
            return $this->handlerCache[$fieldClass];
        }

        // Ensure handlers are sorted by priority
        $this->sortHandlers();

        // Find first handler that can handle this field
        foreach ($this->handlers as $handler) {
            if ($handler->canHandle($field)) {
                // Cache the result
                $this->handlerCache[$fieldClass] = $handler;

                Craft::debug(
                    "Using handler " . get_class($handler) . " for field type: {$fieldClass}",
                    __METHOD__
                );

                return $handler;
            }
        }

        throw new InvalidConfigException(
            "No handler found for field type: {$fieldClass} (handle: {$field->handle})"
        );
    }

    /**
     * Get all registered handlers
     *
     * @return array Array of registered handlers
     */
    public function getHandlers(): array
    {
        $this->sortHandlers();
        return $this->handlers;
    }

    /**
     * Sort handlers by priority (highest first)
     */
    private function sortHandlers(): void
    {
        if ($this->sorted) {
            return;
        }

        usort($this->handlers, function(FieldHandlerInterface $a, FieldHandlerInterface $b) {
            // Higher priority comes first
            return $b->getPriority() <=> $a->getPriority();
        });

        $this->sorted = true;

        Craft::debug(
            'Sorted ' . count($this->handlers) . ' field handlers by priority',
            __METHOD__
        );
    }
}
