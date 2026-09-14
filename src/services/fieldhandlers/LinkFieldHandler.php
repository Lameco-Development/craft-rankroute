<?php

namespace lameco\rankroute\services\fieldhandlers;

use craft\base\FieldInterface;
use craft\fields\Link;

/**
 * Link Field Handler
 *
 * Handles export, import, and comparison of Link fields.
 * Supports Craft's native Link field (craft\fields\Link), Hyper, and Lenz Link plugins.
 */
class LinkFieldHandler extends AbstractFieldHandler
{
    /**
     * @inheritdoc
     */
    public function canHandle(FieldInterface $field): bool
    {
        $fieldClass = get_class($field);

        // Match Craft's native Link field, Hyper plugin, and Lenz Link field plugin
        return $field instanceof Link ||
               str_contains($fieldClass, '\\Hyper\\') ||
               str_contains($fieldClass, 'LinkField') ||
               str_contains($fieldClass, 'lenz\\linkfield');
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
        if (!$value || (is_object($value) && empty((array)$value))) {
            return null;
        }

        if (is_array($value) || (is_object($value) && method_exists($value, 'all'))) {
            $links = is_array($value) ? $value : $value->all();
            $exportedLinks = [];

            foreach ($links as $singleLink) {
                $linkData = $this->exportSingleLink($singleLink);
                if ($linkData) {
                    $exportedLinks[] = $linkData;
                }
            }

            return $exportedLinks ?: null;
        }

        return $this->exportSingleLink($value);
    }

    /**
     * @inheritdoc
     */
    public function import(FieldInterface $field, mixed $value, array $context = []): mixed
    {
        if (!is_array($value) || empty($value)) {
            return null;
        }

        if (isset($value[0]) && is_array($value[0])) {
            return array_map(fn($link) => $this->transformSingleLink($link), $value);
        }

        return $this->transformSingleLink($value);
    }

    /**
     * @inheritdoc
     */
    public function hasChanged(FieldInterface $field, mixed $oldValue, mixed $newValue): bool
    {
        // Export both values to normalized format for comparison
        $oldExported = $this->export($field, $oldValue);
        $newExported = is_array($newValue) ? $newValue : null;

        $oldLinks = $this->normalizeToLinkArray($oldExported);
        $newLinks = $this->normalizeToLinkArray($newExported);

        if (count($oldLinks) !== count($newLinks)) {
            return true;
        }

        foreach ($oldLinks as $index => $oldLink) {
            $newLink = $newLinks[$index] ?? null;

            if (!$newLink) {
                return true;
            }

            if (!$this->areLinksEqual($oldLink, $newLink)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Export a single link object
     *
     * @param mixed $link The link to export
     * @return array|null The exported link data
     */
    private function exportSingleLink(mixed $link): ?array
    {
        if (!$link || !is_object($link)) {
            return null;
        }

        $data = [
            'type' => method_exists($link, 'getType') ? $link->getType() : 'url',
            'url' => method_exists($link, 'getUrl') ? $link->getUrl() : null,
            'label' => method_exists($link, 'getLabel') ? $link->getLabel() : null,
            'target' => method_exists($link, 'getTarget') ? $link->getTarget() : null,
            'ariaLabel' => method_exists($link, 'getAriaLabel') ? $link->getAriaLabel() : null,
        ];

        // Get element ID if this is an element link
        if (method_exists($link, 'getElement')) {
            $element = $link->getElement();
            if ($element) {
                $data['element'] = $element->id;
            }
        }

        return $data;
    }

    /**
     * Transform a single link for import
     *
     * @param mixed $link The link data to transform
     * @return array|null The transformed link data
     */
    private function transformSingleLink(mixed $link): ?array
    {
        if (!is_array($link)) {
            return null;
        }

        $linkData = [];

        $type = $link['type'] ?? 'url';
        $linkData['type'] = $type;

        if (in_array($type, ['entry', 'asset', 'category'])) {
            if (isset($link['element'])) {
                $linkData['value'] = $link['element'];
            }
        } else {
            // isset() already excludes null
            if (isset($link['url'])) {
                $linkData['value'] = $link['url'];
            }
        }

        if (isset($link['label']) && $link['label'] !== '') {
            $linkData['label'] = $link['label'];
        }

        // Set optional properties - only if not null (isset() already excludes null)
        if (isset($link['target'])) {
            $linkData['target'] = $link['target'];
        }
        if (isset($link['ariaLabel'])) {
            $linkData['ariaLabel'] = $link['ariaLabel'];
        }

        return $linkData;
    }

    /**
     * Normalize link data to array format
     *
     * @param mixed $value The value to normalize
     * @return array Array of link data
     */
    private function normalizeToLinkArray(mixed $value): array
    {
        if ($this->isEmpty($value)) {
            return [];
        }

        if (is_array($value) && isset($value[0]) && is_array($value[0])) {
            return $value;
        }

        if (is_array($value) && (isset($value['type']) || isset($value['url']) || isset($value['label']) || isset($value['element']))) {
            return [$value];
        }

        return [];
    }

    /**
     * Compare two link arrays for equality
     *
     * @param array $link1 First link
     * @param array $link2 Second link
     * @return bool True if links are equal
     */
    private function areLinksEqual(array $link1, array $link2): bool
    {
        $type1 = $link1['type'] ?? 'url';
        $type2 = $link2['type'] ?? 'url';

        if ($type1 !== $type2) {
            return false;
        }

        $url1 = $link1['url'] ?? '';
        $url2 = $link2['url'] ?? '';

        if ($url1 !== $url2) {
            return false;
        }

        $text1 = $link1['label'] ?? '';
        $text2 = $link2['label'] ?? '';

        if ($text1 !== $text2) {
            return false;
        }

        $element1 = $link1['element'] ?? null;
        $element2 = $link2['element'] ?? null;

        if ($element1 !== $element2) {
            return false;
        }

        $target1 = $link1['target'] ?? null;
        $target2 = $link2['target'] ?? null;

        if ($target1 !== $target2) {
            return false;
        }

        return true;
    }
}
