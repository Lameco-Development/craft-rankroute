<?php

namespace lameco\rankroute\services\fieldhandlers;

use craft\base\FieldInterface;

/**
 * Abstract Field Handler
 *
 * Base implementation providing common functionality for all field handlers.
 *
 * ## Priority System
 *
 * - **Priority < 50**: Use Craft's native serialization (simple fields like text, numbers, dates)
 * - **Priority >= 50**: Use custom export/import logic (complex fields like Matrix, Assets, Relations)
 *
 * ## When to Create Custom Handlers (priority >= 50)
 *
 * - Complex transformations (element relations to IDs, image optimization)
 * - Special comparison logic for nested structures
 * - External processing requirements
 * - Known serialization compatibility issues
 */
abstract class AbstractFieldHandler implements FieldHandlerInterface
{
    /**
     * @inheritdoc
     */
    public function getPriority(): int
    {
        return 0;
    }

    /**
     * @inheritdoc
     */
    public function useNativeSerialization(): bool
    {
        // Default: handlers with priority < 50 use native serialization
        return $this->getPriority() < 50;
    }

    /**
     * @inheritdoc
     */
    public function hasChanged(FieldInterface $field, mixed $oldValue, mixed $newValue): bool
    {
        // Normalize values for comparison
        $normalizedOld = $this->normalizeForComparison($oldValue);
        $normalizedNew = $this->normalizeForComparison($newValue);

        // Deep comparison
        return $normalizedOld !== $normalizedNew;
    }

    /**
     * Normalize a value for comparison
     *
     * @param mixed $value The value to normalize
     * @return mixed The normalized value
     */
    protected function normalizeForComparison(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $this->normalizeArray($value);
        }

        if (is_object($value)) {
            return $this->normalizeObject($value);
        }

        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    /**
     * Normalize an array for comparison
     *
     * @param array $array The array to normalize
     * @return array The normalized array
     */
    protected function normalizeArray(array $array): array
    {
        $normalized = [];

        foreach ($array as $key => $value) {
            $normalized[$key] = $this->normalizeForComparison($value);
        }

        return $normalized;
    }

    /**
     * Normalize an object for comparison
     *
     * @param object $object The object to normalize
     * @return mixed The normalized representation
     */
    protected function normalizeObject(object $object): mixed
    {
        // If object has a serialization method, use it
        if (method_exists($object, 'toArray')) {
            return $this->normalizeArray($object->toArray());
        }

        if (method_exists($object, '__toString')) {
            return trim((string)$object);
        }

        // Convert to array if possible
        if ($object instanceof \JsonSerializable) {
            return $this->normalizeForComparison($object->jsonSerialize());
        }

        // Last resort: use object properties
        return $this->normalizeArray(get_object_vars($object));
    }

    /**
     * Check if a value is empty (null, empty string, empty array)
     *
     * @param mixed $value The value to check
     * @return bool True if the value is considered empty
     */
    protected function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value) && trim($value) === '') {
            return true;
        }

        if (is_array($value) && empty($value)) {
            return true;
        }

        return false;
    }



    /**
     * Extract IDs from an array or element query result
     *
     * @param mixed $value The value to extract IDs from
     * @return array Array of IDs
     */
    protected function extractIds(mixed $value): array
    {
        if ($this->isEmpty($value)) {
            return [];
        }

        $ids = [];

        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_numeric($item)) {
                    $ids[] = (int)$item;
                } elseif (is_object($item) && isset($item->id)) {
                    $ids[] = (int)$item->id;
                }
            }
        } elseif (is_object($value)) {
            // Handle ElementQuery or similar iterable objects
            if (is_iterable($value)) {
                foreach ($value as $item) {
                    if (is_object($item) && isset($item->id)) {
                        $ids[] = (int)$item->id;
                    }
                }
            } elseif (isset($value->id)) {
                $ids[] = (int)$value->id;
            }
        }

        return $ids;
    }

    /**
     * Compare two arrays of IDs (order-independent)
     *
     * @param array $ids1 First array of IDs
     * @param array $ids2 Second array of IDs
     * @return bool True if the arrays contain the same IDs
     */
    protected function areIdArraysEqual(array $ids1, array $ids2): bool
    {
        if (count($ids1) !== count($ids2)) {
            return false;
        }

        sort($ids1);
        sort($ids2);

        return $ids1 === $ids2;
    }

    /**
     * Log a warning message
     *
     * @param string $message The message to log
     * @param string $category The log category
     */
    protected function logWarning(string $message, string $category = 'rankroute'): void
    {
        \Craft::warning($message, $category);
    }

    /**
     * Log an error message
     *
     * @param string $message The message to log
     * @param string $category The log category
     */
    protected function logError(string $message, string $category = 'rankroute'): void
    {
        \Craft::error($message, $category);
    }
}
