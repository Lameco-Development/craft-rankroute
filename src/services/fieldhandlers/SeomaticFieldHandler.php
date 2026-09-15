<?php

namespace lameco\rankroute\services\fieldhandlers;

use craft\base\FieldInterface;

/**
 * SEOmatic Field Handler
 *
 * Handles export, import, and comparison of SEOmatic fields.
 */
class SeomaticFieldHandler extends AbstractFieldHandler
{
    /**
     * @inheritdoc
     */
    public function canHandle(FieldInterface $field): bool
    {
        $fieldClass = get_class($field);

        return str_contains($fieldClass, 'seomatic') ||
               str_contains($fieldClass, 'SeoSettings') ||
               str_contains($fieldClass, 'Seomatic');
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
        // Always include SEO field structure, even if empty
        // This allows content optimization to know SEO fields are available
        if (!$value) {
            return $this->getEmptySeomaticStructure();
        }

        if (!is_object($value)) {
            return $this->getEmptySeomaticStructure();
        }

        // Use SEOmatic's built-in serialization
        $data = $this->extractSeomaticFields($value);

        return !empty($data) ? $data : $this->getEmptySeomaticStructure();
    }

    /**
     * Extract SEOmatic fields from the MetaBundle object
     *
     * Uses SEOmatic's model properties to extract field data.
     *
     * @param mixed $value The SEOmatic MetaBundle object
     * @return array Extracted SEOmatic fields
     */
    private function extractSeomaticFields(mixed $value): array
    {
        $extracted = [];

        // Define the fields we want to extract
        $fieldNames = [
            'seoTitle',
            'seoDescription',
            'seoKeywords',
            'seoImage',
            'seoImageDescription',
            'ogTitle',
            'ogDescription',
            'ogImage',
            'ogImageDescription',
            'twitterTitle',
            'twitterDescription',
            'twitterImage',
            'twitterImageDescription',
            'canonicalUrl',
            'robots',
        ];

        // Extract from metaGlobalVars if it exists
        if (isset($value->metaGlobalVars) && is_object($value->metaGlobalVars)) {
            foreach ($fieldNames as $fieldName) {
                if (property_exists($value->metaGlobalVars, $fieldName)) {
                    $extracted[$fieldName] = $value->metaGlobalVars->{$fieldName};
                }
            }
        }

        // Extract from metaSiteVars if it exists (overwrites globals)
        if (isset($value->metaSiteVars) && is_object($value->metaSiteVars)) {
            foreach ($fieldNames as $fieldName) {
                if (property_exists($value->metaSiteVars, $fieldName)) {
                    $extracted[$fieldName] = $value->metaSiteVars->{$fieldName};
                }
            }
        }

        // Normalize values: convert empty strings to null, resolve Twig fallbacks
        foreach ($extracted as $fieldName => $value) {
            $extracted[$fieldName] = $this->normalizeSeomaticValue($value);
        }

        // Fill in any missing fields with null
        foreach ($fieldNames as $fieldName) {
            if (!array_key_exists($fieldName, $extracted)) {
                $extracted[$fieldName] = null;
            }
        }

        return $extracted;
    }

    /**
     * Normalize a SEOmatic field value
     *
     * - Converts empty strings to null
     * - Converts Twig template variables ({{ ... }}) to null (these are fallbacks)
     *
     * @param mixed $value The value to normalize
     * @return mixed Normalized value
     */
    private function normalizeSeomaticValue(mixed $value): mixed
    {
        // Convert empty strings to null
        if ($value === '') {
            return null;
        }

        // Convert Twig template fallbacks to null
        // SEOmatic uses {{ seomatic.meta.* }} as fallback values
        if (is_string($value) && preg_match('/^\{\{.*\}\}$/', trim($value))) {
            return null;
        }

        return $value;
    }

    /**
     * Get empty SEOmatic structure with all importable fields
     *
     * Only includes fields that can actually be imported back.
     *
     * @return array Empty SEOmatic structure
     */
    private function getEmptySeomaticStructure(): array
    {
        return [
            // Basic SEO
            'seoTitle' => null,
            'seoDescription' => null,
            'seoKeywords' => null,
            'seoImage' => null,
            'seoImageDescription' => null,

            // Open Graph
            'ogTitle' => null,
            'ogDescription' => null,
            'ogImage' => null,
            'ogImageDescription' => null,

            // Twitter
            'twitterTitle' => null,
            'twitterDescription' => null,
            'twitterImage' => null,
            'twitterImageDescription' => null,

            // Advanced
            'canonicalUrl' => null,
            'robots' => null,
        ];
    }

    /**
     * @inheritdoc
     */
    public function import(FieldInterface $field, mixed $value, array $context = []): mixed
    {
        // Handle null or empty values
        if ($value === null || $value === '') {
            return null;
        }

        // Handle JSON string (backwards compatibility)
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            }
        }

        // If not an array at this point, return as-is
        if (!is_array($value)) {
            return $value;
        }

        // Convert nulls to empty strings (SEOmatic uses empty strings for unset values)
        $normalized = [];
        foreach ($value as $key => $val) {
            $normalized[$key] = ($val === null) ? '' : $val;
        }

        // Wrap in metaGlobalVars structure that SEOmatic expects
        // SEOmatic stores SEO field data (seoTitle, seoDescription, etc.) in metaGlobalVars
        // metaSiteVars contains site identity info (siteName, twitterHandle, etc.)
        return [
            'metaGlobalVars' => $normalized,
        ];
    }

    /**
     * @inheritdoc
     */
    public function hasChanged(FieldInterface $field, mixed $oldValue, mixed $newValue): bool
    {
        $oldExported = $this->export($field, $oldValue);
        $newExported = is_array($newValue) ? $newValue : [];

        if (empty($oldExported) && empty($newExported)) {
            return false;
        }

        if (empty($oldExported) !== empty($newExported)) {
            return true;
        }

        $keysToCompare = [
            'seoTitle',
            'seoDescription',
            'seoKeywords',
            'seoImage',
            'canonicalUrl',
            'robots',
            'ogTitle',
            'ogDescription',
            'ogImage',
            'twitterTitle',
            'twitterDescription',
            'twitterImage',
        ];

        foreach ($keysToCompare as $key) {
            $oldVal = $oldExported[$key] ?? null;
            $newVal = $newExported[$key] ?? null;

            $oldNormalized = $this->normalizeForComparison($oldVal);
            $newNormalized = $this->normalizeForComparison($newVal);

            if ($oldNormalized !== $newNormalized) {
                return true;
            }
        }

        $oldNormalized = $this->normalizeForComparison($oldExported);
        $newNormalized = $this->normalizeForComparison($newExported);

        return $oldNormalized !== $newNormalized;
    }
}
