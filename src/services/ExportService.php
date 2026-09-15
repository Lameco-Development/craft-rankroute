<?php

namespace lameco\rankroute\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use lameco\rankroute\dto\ExportResult;
use yii\web\NotFoundHttpException;

/**
 * Export Service
 *
 * Orchestrates the export of Craft CMS elements using field handlers.
 * Provides methods to export elements by ID, URI, or element object.
 *
 * Works with any element type that has a field layout — entries, Commerce
 * products, categories — because lookups go through Craft's element services
 * rather than a single element query class.
 */
class ExportService extends Component
{
    /**
     * @var FieldHandlerRegistry Field handler registry
     */
    private FieldHandlerRegistry $handlerRegistry;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        // Get the registry from the plugin
        $plugin = \lameco\rankroute\Plugin::getInstance();
        $this->handlerRegistry = $plugin->fieldHandlerRegistry;
    }

    /**
     * Export element by ID
     *
     * @param int $elementId The element ID to export
     * @param int|null $siteId Optional site ID
     * @return ExportResult The export result
     * @throws NotFoundHttpException If element not found
     */
    public function exportById(int $elementId, ?int $siteId = null): ExportResult
    {
        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);

        if (!$element instanceof Element) {
            throw new NotFoundHttpException("Element with ID {$elementId} not found.");
        }

        return $this->exportElement($element);
    }

    /**
     * Export element by URI
     *
     * Resolves the URI against Craft's element index, so it finds whatever
     * element type owns that path: an entry, a Commerce product, a category.
     *
     * @param string $slug The element URI to export
     * @param int|null $siteId Optional site ID, defaults to the current site
     * @return ExportResult The export result
     * @throws NotFoundHttpException If no element owns the URI
     */
    public function exportBySlug(string $slug, ?int $siteId = null): ExportResult
    {
        $element = Craft::$app->getElements()->getElementByUri($slug, $siteId);

        if (!$element instanceof Element) {
            throw new NotFoundHttpException("No element found at path '{$slug}'.");
        }

        return $this->exportElement($element);
    }

    /**
     * Export an element object
     *
     * @param Element $element The element to export
     * @return ExportResult The export result
     */
    public function exportElement(Element $element): ExportResult
    {
        Craft::debug(
            "Exporting element ID {$element->id} ('{$element->title}')",
            __METHOD__
        );

        // Export all custom fields
        $exportedFields = $this->exportFields($element);

        // Create and return the result
        $result = ExportResult::fromElement($element, $exportedFields);

        Craft::info(
            "Successfully exported element ID {$element->id} with " . count($exportedFields) . " fields",
            __METHOD__
        );

        return $result;
    }

    /**
     * Export all custom fields from an element
     *
     * Uses native Craft serialization first, falls back to custom handlers
     * for complex field types or special transformations.
     *
     * Always includes all fields (even empty ones) to support content optimization workflows.
     *
     * @param Element $element The element to export fields from
     * @return array The exported fields (handle => value)
     */
    private function exportFields(Element $element): array
    {
        $exportedFields = [];
        $fieldLayout = $element->getFieldLayout();

        if (!$fieldLayout) {
            Craft::warning(
                "Element ID {$element->id} has no field layout",
                __METHOD__
            );
            return $exportedFields;
        }

        // Get ALL serialized field values from Craft in one call
        // This leverages Craft's native serialization for all field types
        $serializedValues = $element->getSerializedFieldValues();

        Craft::debug(
            "Native serialization returned " . count($serializedValues) . " fields for element ID {$element->id}",
            __METHOD__
        );

        $customFields = $fieldLayout->getCustomFields();

        foreach ($customFields as $field) {
            $handle = $field->handle;

            try {
                // Get the appropriate handler for this field type
                $handler = $this->handlerRegistry->getHandler($field);

                // Check if handler prefers native serialization
                if ($handler->useNativeSerialization() && array_key_exists($handle, $serializedValues)) {
                    // Use Craft's native serialization
                    $exportedValue = $serializedValues[$handle];

                    Craft::debug(
                        "Exported field '{$handle}' using native Craft serialization",
                        __METHOD__
                    );
                } else {
                    // Use custom handler for export (for special transformations)
                    $value = $element->getFieldValue($handle);
                    $exportedValue = $handler->export($field, $value);

                    Craft::debug(
                        "Exported field '{$handle}' using custom handler: " . get_class($handler),
                        __METHOD__
                    );
                }

                // Include all fields, even empty ones (null, empty string, empty array)
                // Only skip if handler explicitly returns false
                // This ensures content optimization flows can identify all available fields
                if ($exportedValue !== false) {
                    $exportedFields[$handle] = $exportedValue;
                } else {
                    Craft::debug(
                        "Field '{$handle}' explicitly excluded by handler (returned false)",
                        __METHOD__
                    );
                }
            } catch (\Exception $e) {
                // Log the error but continue with other fields
                Craft::warning(
                    "Failed to export field '{$handle}' for element ID {$element->id}: " . $e->getMessage(),
                    __METHOD__
                );

                // Optionally include error information in development. App::devMode()
                // instead of YII_DEBUG: PHPStan resolves the latter to a static `false`
                // from yii2's own defined()-guarded fallback in the scanned framework
                // source, which flags the condition as dead code.
                if (\craft\helpers\App::devMode()) {
                    Craft::error(
                        "Field export error details: " . $e->getTraceAsString(),
                        __METHOD__
                    );
                }
            }
        }

        Craft::info(
            "Exported " . count($exportedFields) . " fields for element ID {$element->id}",
            __METHOD__
        );

        return $exportedFields;
    }
}
