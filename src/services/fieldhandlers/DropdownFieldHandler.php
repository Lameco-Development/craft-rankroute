<?php

namespace lameco\rankroute\services\fieldhandlers;

use craft\base\FieldInterface;
use craft\fields\BaseOptionsField;
use craft\fields\Checkboxes;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\MultiSelect;

/**
 * Dropdown Field Handler
 *
 * Handles export, import, and comparison of all option-based fields:
 * single-select (Dropdown, RadioButtons, ButtonGroup) and
 * multi-select (Checkboxes, MultiSelect).
 */
class DropdownFieldHandler extends AbstractFieldHandler
{
    /**
     * @inheritdoc
     */
    public function canHandle(FieldInterface $field): bool
    {
        return $field instanceof BaseOptionsField;
    }

    /**
     * @inheritdoc
     */
    public function getPriority(): int
    {
        return 50;
    }

    /**
     * Whether this field allows multiple selections
     */
    private function isMultiSelect(FieldInterface $field): bool
    {
        return $field instanceof Checkboxes || $field instanceof MultiSelect;
    }

    /**
     * @inheritdoc
     */
    public function export(FieldInterface $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // Multi-select: export as array of {value, label}
        if ($value instanceof MultiOptionsFieldData) {
            $options = [];
            foreach ($value as $option) {
                $options[] = [
                    'value' => $option->value ?? null,
                    'label' => $option->label ?? null,
                ];
            }
            return $options;
        }

        // Single-select: export as {value, label}
        if (is_object($value)) {
            return [
                'value' => $value->value ?? null,
                'label' => $value->label ?? null,
            ];
        }

        if (is_string($value)) {
            return [
                'value' => $value,
                'label' => $value,
            ];
        }

        if (is_array($value)) {
            // Already-exported multi-select (array of {value, label})
            if (isset($value[0]) && is_array($value[0])) {
                return $value;
            }
            // Already-exported single-select
            return [
                'value' => $value['value'] ?? null,
                'label' => $value['label'] ?? null,
            ];
        }

        return $value;
    }

    /**
     * @inheritdoc
     */
    public function import(FieldInterface $field, mixed $value, array $context = []): mixed
    {
        if ($value === null) {
            return null;
        }

        // Detect multi-select from field type OR data shape (array of {value, label} objects)
        $isMulti = $this->isMultiSelect($field) ||
                   (is_array($value) && isset($value[0]) && is_array($value[0]) && isset($value[0]['value']));

        if ($isMulti && is_array($value)) {
            // Array of {value, label} objects
            if (isset($value[0]) && is_array($value[0])) {
                return array_map(fn($opt) => $opt['value'] ?? $opt['label'] ?? null, $value);
            }
            // Already an array of strings
            return $value;
        }

        // Single-select: extract value string
        if (is_array($value) && isset($value['value'])) {
            return $value['value'];
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            return $value['value'] ?? $value['label'] ?? null;
        }

        return $value;
    }

    /**
     * @inheritdoc
     */
    public function hasChanged(FieldInterface $field, mixed $oldValue, mixed $newValue): bool
    {
        if ($this->isMultiSelect($field)) {
            $oldValues = $this->extractMultiValues($oldValue);
            $newValues = $this->extractMultiValues($newValue);
            sort($oldValues);
            sort($newValues);
            return $oldValues !== $newValues;
        }

        $oldDropdownValue = $this->extractSingleValue($oldValue);
        $newDropdownValue = $this->extractSingleValue($newValue);

        return $oldDropdownValue !== $newDropdownValue;
    }

    /**
     * Extract value strings from a multi-select field
     */
    private function extractMultiValues(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if ($value instanceof MultiOptionsFieldData) {
            $values = [];
            foreach ($value as $option) {
                $values[] = (string)$option->value;
            }
            return $values;
        }

        if (is_array($value)) {
            return array_map(function($item) {
                if (is_array($item) && isset($item['value'])) {
                    return (string)$item['value'];
                }
                return (string)$item;
            }, $value);
        }

        return [];
    }

    /**
     * Extract the actual value from a single-select option field
     */
    private function extractSingleValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value) && isset($value->value)) {
            return (string)$value->value;
        }

        if (is_array($value) && isset($value['value'])) {
            return (string)$value['value'];
        }

        if (is_string($value)) {
            return $value;
        }

        return null;
    }
}
