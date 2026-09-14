<?php

namespace lameco\rankroute\services\fieldhandlers;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\fields\BaseRelationField;

/**
 * Relation Field Handler
 *
 * Handles export, import, and comparison of all relational fields
 * (Entries, Categories, Tags, Users, and any custom relation fields).
 */
class RelationFieldHandler extends AbstractFieldHandler
{
    /**
     * @inheritdoc
     */
    public function canHandle(FieldInterface $field): bool
    {
        return $field instanceof BaseRelationField;
    }

    /**
     * @inheritdoc
     */
    public function getPriority(): int
    {
        return 50;
    }

    /**
     * @inheritdoc
     */
    public function export(FieldInterface $field, mixed $value): mixed
    {
        if (!is_iterable($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $element) {
            if ($element instanceof ElementInterface) {
                $ids[] = $element->id;
            }
        }

        return $ids;
    }

    /**
     * @inheritdoc
     */
    public function import(FieldInterface $field, mixed $value, array $context = []): mixed
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            if (is_numeric($item)) {
                $ids[] = (int)$item;
            }
        }

        return $ids;
    }

    /**
     * @inheritdoc
     */
    public function hasChanged(FieldInterface $field, mixed $oldValue, mixed $newValue): bool
    {
        // Extract IDs from both values using parent's method
        $oldIds = $this->extractIds($oldValue);
        $newIds = $this->extractIds($newValue);

        // Compare ID arrays (order-independent)
        return !$this->areIdArraysEqual($oldIds, $newIds);
    }
}
