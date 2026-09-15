<?php

namespace lameco\rankroute\services\fieldhandlers;

use craft\base\FieldInterface;
use craft\fields\Date;
use craft\fields\Lightswitch;
use craft\fields\Number;
use craft\fields\Time;

/**
 * Default Field Handler
 *
 * Fallback handler for simple field types (text, numbers, dates, booleans).
 * Has lowest priority (-100) so it only runs when no specialized handler exists
 * or native Craft serialization fails.
 *
 * Provides basic type coercion and validation as a safety net.
 */
class DefaultFieldHandler extends AbstractFieldHandler
{
    /**
     * @inheritdoc
     */
    public function canHandle(FieldInterface $field): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getPriority(): int
    {
        // Lowest priority - prefer native Craft serialization
        return -100;
    }

    /**
     * @inheritdoc
     */
    public function export(FieldInterface $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // Handle DateTime objects (Date/Time fields)
        if ($value instanceof \DateTime) {
            return $value->format(\DateTime::ATOM);
        }

        // Handle objects with standard serialization methods
        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string)$value;
            }

            if (method_exists($value, 'toArray')) {
                return $value->toArray();
            }

            if ($value instanceof \JsonSerializable) {
                return $value->jsonSerialize();
            }

            $this->logWarning(
                "Cannot serialize object of type " . get_class($value) .
                " for field '{$field->handle}'. Consider creating a specialized handler.",
                'rankroute'
            );
            return null;
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

        if ($this->isDateTimeField($field) && is_string($value)) {
            try {
                return new \DateTime($value);
            } catch (\Exception $e) {
                $this->logWarning(
                    "Invalid DateTime string '{$value}' for field '{$field->handle}': {$e->getMessage()}",
                    'rankroute'
                );
                return null;
            }
        }

        if ($field instanceof Lightswitch) {
            return (bool)filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if ($field instanceof Number) {
            if (!is_numeric($value)) {
                $this->logWarning(
                    "Non-numeric value '{$value}' for Number field '{$field->handle}'",
                    'rankroute'
                );
                return null;
            }
            return str_contains((string)$value, '.') ? (float)$value : (int)$value;
        }

        return $value;
    }

    /**
     * @inheritdoc
     */
    public function hasChanged(FieldInterface $field, mixed $oldValue, mixed $newValue): bool
    {
        if ($this->isDateTimeField($field)) {
            return $this->hasDateTimeChanged($oldValue, $newValue);
        }

        if ($field instanceof Number) {
            return $this->hasNumericChanged($oldValue, $newValue);
        }

        if ($field instanceof Lightswitch) {
            return $this->hasBooleanChanged($oldValue, $newValue);
        }

        return parent::hasChanged($field, $oldValue, $newValue);
    }

    /**
     * Check if a field is a Date or Time field
     *
     * @param FieldInterface $field The field to check
     * @return bool True if it's a Date or Time field
     */
    private function isDateTimeField(FieldInterface $field): bool
    {
        return $field instanceof Date || $field instanceof Time;
    }

    /**
     * Compare DateTime values
     *
     * @param mixed $oldValue The old value
     * @param mixed $newValue The new value
     * @return bool True if values are different
     */
    private function hasDateTimeChanged(mixed $oldValue, mixed $newValue): bool
    {
        $oldTimestamp = $this->getDateTimeTimestamp($oldValue);
        $newTimestamp = $this->getDateTimeTimestamp($newValue);

        return $oldTimestamp !== $newTimestamp;
    }

    /**
     * Get timestamp from a DateTime value
     *
     * @param mixed $value The value to extract timestamp from
     * @return int|null The timestamp or null
     */
    private function getDateTimeTimestamp(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTime) {
            return $value->getTimestamp();
        }

        if (is_string($value)) {
            try {
                $date = new \DateTime($value);
                return $date->getTimestamp();
            } catch (\Exception $e) {
                return null;
            }
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        return null;
    }

    /**
     * Compare numeric values
     *
     * @param mixed $oldValue The old value
     * @param mixed $newValue The new value
     * @return bool True if values are different
     */
    private function hasNumericChanged(mixed $oldValue, mixed $newValue): bool
    {
        $oldNum = is_numeric($oldValue) ? (float)$oldValue : null;
        $newNum = is_numeric($newValue) ? (float)$newValue : null;

        return $oldNum !== $newNum;
    }

    /**
     * Compare boolean values
     *
     * @param mixed $oldValue The old value
     * @param mixed $newValue The new value
     * @return bool True if values are different
     */
    private function hasBooleanChanged(mixed $oldValue, mixed $newValue): bool
    {
        $oldBool = filter_var($oldValue, FILTER_VALIDATE_BOOLEAN);
        $newBool = filter_var($newValue, FILTER_VALIDATE_BOOLEAN);

        return $oldBool !== $newBool;
    }
}
