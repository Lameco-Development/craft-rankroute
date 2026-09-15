<?php

namespace lameco\rankroute\services\fieldhandlers;

use Craft;
use craft\base\FieldInterface;
use craft\fields\Assets;
use craft\fields\Dropdown;
use craft\fields\Link;
use craft\fields\Matrix;
use lameco\rankroute\services\FieldHandlerRegistry;

/**
 * Matrix Field Handler
 *
 * Handles export, import, and comparison of Matrix/Neo block fields.
 *
 * ## Delegation Pattern
 *
 * Delegates block field operations to specialized handlers via FieldHandlerRegistry:
 * - Export/Import: Routes to appropriate handler (LinkFieldHandler, AssetFieldHandler, etc.)
 * - Uses "mock fields" during import to enable delegation when actual field objects unavailable
 * - Ensures consistent transformation logic whether fields are standalone or in Matrix blocks
 */
class MatrixFieldHandler extends AbstractFieldHandler
{
    /**
     * @var FieldHandlerRegistry|null Field handler registry for delegating block field operations
     */
    private ?FieldHandlerRegistry $handlerRegistry = null;

    /**
     * Get the field handler registry (lazy initialization)
     *
     * @return FieldHandlerRegistry
     */
    private function getHandlerRegistry(): FieldHandlerRegistry
    {
        if ($this->handlerRegistry === null) {
            $plugin = \lameco\rankroute\Plugin::getInstance();
            $this->handlerRegistry = $plugin->fieldHandlerRegistry;
        }

        return $this->handlerRegistry;
    }

    /**
     * Get the short class name for a handler (e.g., "AssetFieldHandler")
     *
     * @param FieldHandlerInterface $handler
     * @return string The short class name
     */
    private function getHandlerShortName(FieldHandlerInterface $handler): string
    {
        $className = get_class($handler);
        $parts = explode('\\', $className);
        return end($parts);
    }

    /**
     * @inheritdoc
     */
    public function canHandle(FieldInterface $field): bool
    {
        $fieldClass = get_class($field);

        // Handle Matrix fields and any field containing "Matrix" in the class name
        return $field instanceof Matrix ||
               strpos($fieldClass, 'Matrix') !== false ||
               strpos($fieldClass, 'Neo') !== false;
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
        if ($this->isEmpty($value) || !is_iterable($value)) {
            return [];
        }

        $exportedBlocks = [];

        foreach ($value as $block) {
            if (!is_object($block)) {
                continue;
            }

            $blockType = $block->type->handle ?? 'unknown';
            $blockData = [
                'type' => $blockType,
            ];

            // Export native block properties (title, enabled, collapsed)
            // In Craft 5, Matrix blocks are entries and have these native properties
            // Always include these, even if empty, for content optimization workflows
            if (property_exists($block, 'title')) {
                $blockData['title'] = $block->title ?? null;
            }
            if (property_exists($block, 'enabled')) {
                $blockData['enabled'] = $block->enabled ?? false;
            }
            if (property_exists($block, 'collapsed')) {
                $blockData['collapsed'] = $block->collapsed ?? false;
            }

            // Export all fields in the block
            $fieldLayout = $block->getFieldLayout();
            if (!$fieldLayout) {
                $exportedBlocks[] = $blockData;
                continue;
            }

            $customFields = $fieldLayout->getCustomFields();
            $fieldHandles = array_map(fn($f) => $f->handle, $customFields);

            Craft::info(
                "Exporting Matrix block type '{$blockType}' with title='" . ($block->title ?? 'NULL') . "' and fields: " . implode(', ', $fieldHandles),
                __METHOD__
            );

            foreach ($customFields as $blockField) {
                $fieldHandle = $blockField->handle;
                $fieldValue = $block->getFieldValue($fieldHandle);

                try {
                    // Check if the value is an ElementQuery object
                    if (is_object($fieldValue) && $fieldValue instanceof \craft\elements\db\ElementQuery) {
                        // Execute the query to get actual elements
                        $fieldValue = $fieldValue->all();
                    }

                    $exportedValue = $this->exportBlockField($blockField, $fieldValue);

                    // Log what we're exporting for debugging
                    if ($fieldHandle === 'title') {
                        Craft::info(
                            "Block '{$blockType}' title field: raw='" .
                            (is_null($fieldValue) ? 'NULL' : (is_string($fieldValue) ? $fieldValue : gettype($fieldValue))) .
                            "', exported='" .
                            (is_null($exportedValue) ? 'NULL' : (is_string($exportedValue) ? $exportedValue : gettype($exportedValue))) . "'",
                            __METHOD__
                        );
                    }

                    // Include all fields, even empty ones (null, empty string, empty array)
                    // Only skip if handler explicitly returns false
                    // This ensures content optimization flows can identify all available fields
                    if ($exportedValue !== false) {
                        $blockData[$fieldHandle] = $exportedValue;
                    } else {
                        Craft::debug(
                            "Block '{$blockType}' field '{$fieldHandle}' explicitly excluded by handler (returned false)",
                            __METHOD__
                        );
                    }
                } catch (\Exception $e) {
                    $this->logWarning(
                        "Skipping block field '{$fieldHandle}': " . $e->getMessage(),
                        'rankroute'
                    );
                }
            }

            Craft::info(
                "Block '{$blockType}' exported with fields: " . implode(', ', array_keys($blockData)),
                __METHOD__
            );

            $exportedBlocks[] = $blockData;
        }

        return $exportedBlocks;
    }

    /**
     * @inheritdoc
     */
    public function import(FieldInterface $field, mixed $value, array $context = []): mixed
    {
        if (!is_array($value)) {
            return [];
        }

        // CRITICAL: Craft CMS requires ALL blocks to be provided when updating Matrix fields
        // We use the complete block data from the import JSON, which should contain all blocks
        // with 'new1', 'new2', etc. keys as Craft expects

        $transformedBlocks = [];

        Craft::info(
            "Matrix import: Processing " . count($value) . " blocks from import data",
            __METHOD__
        );

        // Process each block from the import
        $blockNumber = 1;
        foreach ($value as $block) {
            if (!is_array($block)) {
                continue;
            }

            $blockType = $block['type'] ?? null;
            if (!$blockType) {
                $this->logWarning(
                    "Skipping block without type",
                    'rankroute'
                );
                continue;
            }

            Craft::info(
                "Matrix import: Processing block {$blockNumber}, type '{$blockType}' with " . (count($block) - 1) . " fields",
                __METHOD__
            );

            // Build block data with fields nested under 'fields' key (Craft 5 format)
            $blockData = [
                'type' => $blockType,
                'enabled' => $block['enabled'] ?? true,
                'collapsed' => $block['collapsed'] ?? false,
                'fields' => [], // Craft expects field values nested here
            ];

            // Handle native title property separately (not a custom field)
            if (isset($block['title'])) {
                $blockData['title'] = $block['title'];
            }

            // Transform each field value and nest under 'fields'
            foreach ($block as $key => $blockValue) {
                if ($key !== 'type' && $key !== 'enabled' && $key !== 'collapsed' && $key !== 'title') {
                    try {
                        $transformedValue = $this->transformBlockField($key, $blockValue);
                        $blockData['fields'][$key] = $transformedValue;

                        Craft::debug(
                            "Matrix import: Block {$blockNumber} field '{$key}' set (value type: " . gettype($transformedValue) . ")",
                            __METHOD__
                        );
                    } catch (\Exception $e) {
                        $this->logWarning(
                            "Failed to transform block field '{$key}': " . $e->getMessage(),
                            'rankroute'
                        );
                    }
                }
            }

            // Use 'new' keys for Craft to create/replace blocks
            $blockKey = 'new' . $blockNumber;
            $transformedBlocks[$blockKey] = $blockData;

            $blockNumber++;
        }

        Craft::info(
            "Matrix import: Returning " . count($transformedBlocks) . " blocks with 'new' keys for Craft to save",
            __METHOD__
        );

        Craft::debug(
            "Matrix import: Final block keys: " . json_encode(array_keys($transformedBlocks)),
            __METHOD__
        );

        return $transformedBlocks;
    }

    /**
     * @inheritdoc
     */
    public function hasChanged(FieldInterface $field, mixed $oldValue, mixed $newValue): bool
    {
        // Normalize both values to arrays of blocks
        $oldBlocks = $this->normalizeBlocks($oldValue);
        $newBlocks = $this->normalizeBlocks($newValue);

        // Compare block counts
        if (count($oldBlocks) !== count($newBlocks)) {
            return true;
        }

        // Compare each block
        foreach ($oldBlocks as $index => $oldBlock) {
            $newBlock = $newBlocks[$index] ?? null;

            if (!$newBlock) {
                return true;
            }

            // Compare block types
            if (($oldBlock['type'] ?? null) !== ($newBlock['type'] ?? null)) {
                return true;
            }

            // Compare enabled status
            if (($oldBlock['enabled'] ?? true) !== ($newBlock['enabled'] ?? true)) {
                return true;
            }

            // Compare block fields
            $oldFields = array_diff_key($oldBlock, ['type' => 1, 'enabled' => 1, 'collapsed' => 1]);
            $newFields = array_diff_key($newBlock, ['type' => 1, 'enabled' => 1, 'collapsed' => 1]);

            if (array_keys($oldFields) !== array_keys($newFields)) {
                return true;
            }

            foreach ($oldFields as $fieldHandle => $oldFieldValue) {
                $newFieldValue = $newFields[$fieldHandle] ?? null;

                // Use normalized comparison for field values
                if ($this->normalizeForComparison($oldFieldValue) !== $this->normalizeForComparison($newFieldValue)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Export a block field value using the appropriate field handler
     *
     * This method delegates to the registered field handlers via the FieldHandlerRegistry,
     * ensuring consistent handling across all field types.
     *
     * @param FieldInterface $field The field to export
     * @param mixed $value The field value
     * @return mixed The exported value (false to exclude from export)
     */
    private function exportBlockField(FieldInterface $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // Handle empty arrays (from executed ElementQueries with no results)
        if (is_array($value) && empty($value)) {
            return [];
        }

        // Get the appropriate handler for this field type. getHandler() never returns
        // null (DefaultFieldHandler::canHandle() always matches), so a failure here can
        // only be the try/catch below, not a missing handler.
        $handler = $this->getHandlerRegistry()->getHandler($field);

        try {
            if ($handler === $this) {
                // Recursive Matrix-within-Matrix: export sub-blocks and wrap with hint
                $exported = $this->export($field, $value);
                if ($exported !== null && $exported !== false) {
                    return [
                        '__handler' => 'MatrixFieldHandler',
                        '__value' => $exported,
                    ];
                }
                return $exported;
            }

            $exported = $handler->export($field, $value);
            // Wrap with handler hint for import — skip for DefaultFieldHandler (simple values stay simple)
            if (!$handler instanceof DefaultFieldHandler && $exported !== null && $exported !== false) {
                return [
                    '__handler' => $this->getHandlerShortName($handler),
                    '__value' => $exported,
                ];
            }
            return $exported;
        } catch (\Exception $e) {
            $this->logWarning(
                "Handler for field type '" . get_class($field) . "' failed: " . $e->getMessage(),
                'rankroute'
            );
        }

        // Fallback: Handle basic types that don't need special processing

        // Handle Table fields
        if (get_class($field) === 'craft\\fields\\Table') {
            return $value; // Tables are already arrays
        }

        // Handle CKEditor/Redactor fields
        $fieldClass = get_class($field);
        if (strpos($fieldClass, 'CKEditor') !== false || strpos($fieldClass, 'Redactor') !== false) {
            return (string)$value;
        }

        // Handle plain text, number, etc.
        if (is_string($value) || is_numeric($value) || is_bool($value)) {
            return $value;
        }

        // Handle arrays
        if (is_array($value)) {
            return $value;
        }

        // Handle unknown objects
        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                try {
                    return (string)$value;
                } catch (\Exception $e) {
                    $this->logWarning(
                        "Cannot export block field object of type: " . get_class($value),
                        'rankroute'
                    );
                    return false;
                }
            }

            $this->logWarning(
                "Skipping unknown block field object type: " . get_class($value),
                'rankroute'
            );
            return false;
        }

        return $value;
    }

    /**
     * Transform a block field value for import using the appropriate field handler
     *
     * This method detects the field type from the data structure and delegates
     * transformation to the appropriate specialized handler.
     *
     * ## Detection Strategy
     *
     * We identify field types by examining the data structure:
     * - Dropdown: array with 'value' and 'label' keys
     * - Asset: array with 'metadata.id' key
     * - Link: array with 'type', 'url', 'label', or 'element' keys
     *
     * ## Mock Field Approach
     *
     * Since we don't have the actual field object during import, we create minimal
     * mock field objects to satisfy the handler's import() method signature.
     *
     * ## Fallback Behavior
     *
     * If no handler is found, we apply basic fallback logic (extract IDs, values, etc.)
     * and log a warning for non-basic field types.
     *
     * @param string $fieldHandle The field handle (used for context/logging)
     * @param mixed $value The field value from import data
     * @return mixed The transformed value ready for Craft
     */
    private function transformBlockField(string $fieldHandle, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // Handle ElementQuery strings from export (convert to empty array)
        if (is_string($value) && (strpos($value, 'ElementQuery') !== false || strpos($value, 'craft\\elements\\db\\') !== false)) {
            return [];
        }

        // Check for handler hint from export (preferred path — no guessing needed)
        if (is_array($value) && isset($value['__handler']) && array_key_exists('__value', $value)) {
            $handlerName = $value['__handler'];
            $actualValue = $value['__value'];

            // Handle recursive Matrix blocks
            if ($handlerName === 'MatrixFieldHandler') {
                return $this->importNestedMatrixBlocks($actualValue);
            }

            // Find the handler by its short class name
            $handler = $this->findHandlerByClassName('', $handlerName);
            if ($handler) {
                $mockField = $this->createMockFieldForHandler($handler);
                if ($mockField) {
                    return $handler->import($mockField, $actualValue);
                }
            }

            // If handler not found, fall through to heuristic detection with unwrapped value
            Craft::warning(
                "Handler '{$handlerName}' not found for field '{$fieldHandle}', falling back to heuristic detection",
                __METHOD__
            );
            $value = $actualValue;
        }

        // Try to detect field type from the value structure and delegate to appropriate handler

        // Dropdown fields - array with 'value' key
        if (is_array($value) && isset($value['value']) && isset($value['label'])) {
            $handler = $this->findHandlerByClassName('craft\\fields\\Dropdown', 'DropdownFieldHandler');

            if ($handler) {
                // Create a minimal mock field that satisfies the interface
                $mockField = $this->createMockDropdownField();
                return $handler->import($mockField, $value);
            }

            // Fallback
            return $value['value'];
        }

        // Asset fields - single asset with metadata
        if (is_array($value) && isset($value['id'])) {
            $handler = $this->findHandlerByClassName('craft\\fields\\Assets', 'AssetFieldHandler');

            if ($handler) {
                $mockField = $this->createMockAssetsField();
                return $handler->import($mockField, $value);
            }

            // Fallback
            return [$value['id']];
        }

        // Link fields - check for link-identifying properties
        if (is_array($value) && (isset($value['type']) || isset($value['url']) || isset($value['text']) || isset($value['label']) || isset($value['element']) || isset($value['elementType']))) {
            $handler = $this->findHandlerByClassName('lenz\\linkfield\\fields\\LinkField', 'LinkFieldHandler');

            if ($handler) {
                $mockField = $this->createMockLinkField();
                return $handler->import($mockField, $value);
            }

            // Fallback - should not happen if LinkFieldHandler is registered
            Craft::warning(
                "No LinkFieldHandler found for transforming link field '{$fieldHandle}'",
                __METHOD__
            );
            return $value;
        }

        // Handle arrays of options, assets, or links
        if (is_array($value) && !empty($value)) {
            $firstItem = reset($value);

            // Array of option values (multi-select: Checkboxes, MultiSelect)
            // Distinguish from links: options have exactly {value, label}, links have {type, url, element, ...}
            if (is_array($firstItem) && isset($firstItem['value']) && isset($firstItem['label']) && !isset($firstItem['type']) && !isset($firstItem['url']) && !isset($firstItem['element'])) {
                $handler = $this->findHandlerByClassName('craft\\fields\\Dropdown', 'DropdownFieldHandler');

                if ($handler) {
                    $mockField = $this->createMockDropdownField();
                    return $handler->import($mockField, $value);
                }

                // Fallback
                return array_map(fn($opt) => $opt['value'], $value);
            }

            // Array of links
            if (is_array($firstItem) && (isset($firstItem['type']) || isset($firstItem['url']) || isset($firstItem['text']) || isset($firstItem['label']) || isset($firstItem['element']))) {
                $handler = $this->findHandlerByClassName('lenz\\linkfield\\fields\\LinkField', 'LinkFieldHandler');

                if ($handler) {
                    $mockField = $this->createMockLinkField();
                    return $handler->import($mockField, $value);
                }

                // Fallback
                Craft::warning(
                    "No LinkFieldHandler found for transforming array of links in field '{$fieldHandle}'",
                    __METHOD__
                );
                return $value;
            }

            // Array of assets
            if (is_array($firstItem) && isset($firstItem['id'])) {
                $handler = $this->findHandlerByClassName('craft\\fields\\Assets', 'AssetFieldHandler');

                if ($handler) {
                    $mockField = $this->createMockAssetsField();
                    return $handler->import($mockField, $value);
                }

                // Fallback
                return array_map(fn($asset) => $asset['id'], $value);
            }
        }

        // Return value as-is for basic types
        return $value;
    }

    /**
     * Import nested Matrix blocks (Matrix-within-Matrix)
     *
     * Recursively processes sub-blocks through the same import logic.
     * Used when a Matrix block field itself contains another Matrix field.
     *
     * @param mixed $value The nested block data
     * @return mixed The transformed block data for Craft
     */
    private function importNestedMatrixBlocks(mixed $value): mixed
    {
        if (!is_array($value)) {
            return [];
        }

        $mockField = new Matrix();
        $mockField->handle = 'nestedMatrix';

        return $this->import($mockField, $value);
    }

    /**
     * Create a mock field appropriate for the given handler
     *
     * Maps handler class names to their corresponding field types.
     * Used during import when actual field objects aren't available.
     *
     * @param FieldHandlerInterface $handler The handler to create a mock field for
     * @return FieldInterface|null The mock field, or null if no mapping exists
     */
    private function createMockFieldForHandler(FieldHandlerInterface $handler): ?FieldInterface
    {
        $handlerClass = get_class($handler);

        if (str_contains($handlerClass, 'DropdownFieldHandler')) {
            return $this->createMockDropdownField();
        }
        if (str_contains($handlerClass, 'AssetFieldHandler')) {
            return $this->createMockAssetsField();
        }
        if (str_contains($handlerClass, 'LinkFieldHandler')) {
            return $this->createMockLinkField();
        }
        if (str_contains($handlerClass, 'SeomaticFieldHandler')) {
            $field = new \craft\fields\PlainText();
            $field->handle = 'mockSeomatic';
            return $field;
        }
        if (str_contains($handlerClass, 'RelationFieldHandler')) {
            $field = new \craft\fields\Entries();
            $field->handle = 'mockEntries';
            return $field;
        }
        if (str_contains($handlerClass, 'DefaultFieldHandler')) {
            $field = new \craft\fields\PlainText();
            $field->handle = 'mockDefault';
            return $field;
        }

        Craft::warning(
            "No mock field mapping for handler: {$handlerClass}",
            __METHOD__
        );
        return null;
    }

    /**
     * Find a handler by checking handler class name
     *
     * Iterates through registered handlers to find one matching the given handler
     * class name pattern (e.g., "LinkFieldHandler").
     *
     * This is used during import when we don't have the actual field object but
     * need to delegate to a specific handler based on detected field type.
     *
     * @param string $fieldClassName The field class name (not currently used but kept for future use)
     * @param string $handlerClassName The handler class name to match (partial match)
     * @return FieldHandlerInterface|null The found handler or null
     */
    private function findHandlerByClassName(string $fieldClassName, string $handlerClassName): ?FieldHandlerInterface
    {
        foreach ($this->getHandlerRegistry()->getHandlers() as $handler) {
            // Skip self to avoid recursion
            if ($handler === $this) {
                continue;
            }

            // Check if handler class name matches (e.g., "LinkFieldHandler")
            $handlerClass = get_class($handler);
            if (strpos($handlerClass, $handlerClassName) !== false) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * Create a mock Dropdown field
     *
     * Creates a minimal object that implements FieldInterface but doesn't have
     * any real functionality. This is used to satisfy the handler's import() method
     * signature when we don't have access to the actual field object.
     *
     * The handler only uses the field for type checking (already done), so this
     * mock object is sufficient.
     *
     * @return FieldInterface
     */
    private function createMockDropdownField(): FieldInterface
    {
        $field = new Dropdown();
        $field->handle = 'mockDropdown';
        return $field;
    }

    /**
     * Create a mock Assets field
     *
     * Creates a minimal mock field object for delegating to AssetFieldHandler.
     * See createMockDropdownField() for rationale.
     *
     * @return FieldInterface
     */
    private function createMockAssetsField(): FieldInterface
    {
        $field = new Assets();
        $field->handle = 'mockAssets';
        return $field;
    }

    /**
     * Create a mock Link field
     *
     * Creates a minimal mock field object for delegating to LinkFieldHandler.
     * See createMockDropdownField() for rationale.
     *
     * @return FieldInterface
     */
    private function createMockLinkField(): FieldInterface
    {
        $field = new Link();
        $field->handle = 'mockLink';
        return $field;
    }

    /**
     * Normalize Matrix blocks for comparison
     *
     * @param mixed $value The value to normalize
     * @return array Array of normalized blocks
     */
    private function normalizeBlocks(mixed $value): array
    {
        if ($this->isEmpty($value)) {
            return [];
        }

        $blocks = [];

        // Handle array format (from import/export)
        if (is_array($value)) {
            // Strip 'new1', 'new2' keys if present
            foreach ($value as $block) {
                if (is_array($block) && isset($block['type'])) {
                    $blocks[] = $block;
                }
            }
            return $blocks;
        }

        // Handle block objects (from Craft)
        if (is_iterable($value)) {
            foreach ($value as $block) {
                if (!is_object($block)) {
                    continue;
                }

                $blockData = [
                    'type' => $block->type->handle ?? 'unknown',
                    'enabled' => $block->enabled ?? true,
                ];

                // Add field values
                $fieldLayout = $block->getFieldLayout();
                if ($fieldLayout) {
                    foreach ($fieldLayout->getCustomFields() as $field) {
                        $blockData[$field->handle] = $block->getFieldValue($field->handle);
                    }
                }

                $blocks[] = $blockData;
            }
        }

        return $blocks;
    }
}
