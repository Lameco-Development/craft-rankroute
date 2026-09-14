<?php

namespace lameco\rankroute\services\fieldhandlers;

use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\fields\Assets;

/**
 * Asset Field Handler
 *
 * Handles export, import, and comparison of Asset fields.
 */
class AssetFieldHandler extends AbstractFieldHandler
{
    /**
     * @inheritdoc
     */
    public function canHandle(FieldInterface $field): bool
    {
        $fieldClass = get_class($field);

        return $field instanceof Assets ||
               str_contains($fieldClass, 'Assets');
    }

    /**
     * @inheritdoc
     */
    public function getPriority(): int
    {
        // High priority - specialized handler
        return 50;
    }

    /**
     * @inheritdoc
     */
    public function export(FieldInterface $field, mixed $value): mixed
    {
        if (!is_iterable($value)) {
            return null;
        }

        $exportedAssets = [];

        foreach ($value as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }

            $assetData = $this->exportAsset($asset);
            if ($assetData) {
                $exportedAssets[] = $assetData;
            }
        }

        // Return single asset if only one, otherwise return array
        if (count($exportedAssets) === 1) {
            return $exportedAssets[0];
        }

        return $exportedAssets ?: null;
    }

    /**
     * @inheritdoc
     */
    public function import(FieldInterface $field, mixed $value, array $context = []): mixed
    {
        if (!is_array($value)) {
            return [];
        }

        if (isset($value['id'])) {
            return [$value['id']];
        }

        $assetIds = [];
        foreach ($value as $asset) {
            if (is_array($asset) && isset($asset['id'])) {
                $assetIds[] = $asset['id'];
            } elseif (is_numeric($asset)) {
                $assetIds[] = (int)$asset;
            }
        }

        return $assetIds;
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

    /**
     * Export a single asset with essential content data only
     *
     * @param Asset $asset The asset to export
     * @return array The exported asset data
     */
    private function exportAsset(Asset $asset): array
    {
        return [
            'id' => $asset->id,
            'url' => $asset->getUrl(),
            'title' => $asset->title,
            'alt' => $asset->alt ?? null,
        ];
    }
}
