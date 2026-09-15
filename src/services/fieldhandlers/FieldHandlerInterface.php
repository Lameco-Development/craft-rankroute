<?php

namespace lameco\rankroute\services\fieldhandlers;

use craft\base\FieldInterface;

/**
 * Field Handler Interface
 *
 * Defines the contract for handling field export, import, and comparison operations.
 * Each field type (Matrix, Assets, Relations, etc.) should have its own handler implementation.
 */
interface FieldHandlerInterface
{
    /**
     * Determine if this handler can handle the given field type
     *
     * @param FieldInterface $field The field to check
     * @return bool True if this handler can process the field
     */
    public function canHandle(FieldInterface $field): bool;

    /**
     * Export a field value to a serializable format
     *
     * @param FieldInterface $field The field being exported
     * @param mixed $value The field value to export
     * @return mixed The exported value (must be JSON serializable)
     */
    public function export(FieldInterface $field, mixed $value): mixed;

    /**
     * Import a field value and transform it for saving to an entry
     *
     * @param FieldInterface $field The field being imported
     * @param mixed $value The imported value from JSON
     * @param array $context Optional context data (e.g., ['draft' => Entry, 'fieldHandle' => string])
     * @return mixed The transformed value ready to be set on an entry
     */
    public function import(FieldInterface $field, mixed $value, array $context = []): mixed;

    /**
     * Compare two field values to determine if they have changed
     *
     * @param FieldInterface $field The field being compared
     * @param mixed $oldValue The original field value from the entry
     * @param mixed $newValue The new field value from import data
     * @return bool True if the values are different and should be updated
     */
    public function hasChanged(FieldInterface $field, mixed $oldValue, mixed $newValue): bool;

    /**
     * Get the priority of this handler (higher priority = checked first)
     * Used when multiple handlers might claim to handle a field type.
     *
     * @return int Priority value (default: 0)
     */
    public function getPriority(): int;

    /**
     * Determine if this handler prefers native Craft serialization
     *
     * By default, handlers with priority < 50 use native serialization.
     * Override this method to explicitly control serialization strategy.
     *
     * @return bool True to use native Craft serialization, false for custom export/import
     */
    public function useNativeSerialization(): bool;
}
