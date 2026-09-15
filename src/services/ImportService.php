<?php

namespace lameco\rankroute\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use lameco\rankroute\dto\ImportResult;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

/**
 * Import Service
 *
 * Orchestrates the import of element data using field handlers.
 * Handles JSON parsing, element lookup, draft creation, and field updates.
 *
 * Works with any element type that supports drafts — entries, Commerce
 * products, categories — because the element is looked up by ID through
 * Craft's element service rather than a single element query class.
 */
class ImportService extends Component
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
     * Import entry from array data
     *
     * @param array $data The import data
     * @param int|null $userId User ID for draft creation (defaults to current user)
     * @return ImportResult The import result
     * @throws BadRequestHttpException If data is invalid
     * @throws NotFoundHttpException If entry not found
     */
    public function importFromArray(array $data, ?int $userId = null): ImportResult
    {
        // Validate data structure
        if (empty($data)) {
            throw new BadRequestHttpException('Import data is empty or invalid.');
        }

        // Handle both single object and array format
        $importData = $this->normalizeImportData($data);

        // Get element ID from metadata
        $entryId = $importData['metadata']['id'] ?? null;

        if (!$entryId) {
            throw new BadRequestHttpException('Element ID not found in import data.');
        }

        // Get site ID from metadata
        $siteId = $importData['metadata']['siteId'] ?? 1;

        // Fetch the original element
        $originalEntry = Craft::$app->getElements()->getElementById($entryId, null, $siteId);

        if (!$originalEntry instanceof Element) {
            throw new NotFoundHttpException("Element with ID {$entryId} not found.");
        }

        Craft::debug(
            "Importing data for entry ID {$entryId} ('{$originalEntry->title}')",
            __METHOD__
        );

        // Detect changed fields by comparing original entry vs import data
        $changedFields = $this->detectChangedFields($originalEntry, $importData);

        // If no changes, return early WITHOUT creating a draft
        if (empty($changedFields)) {
            Craft::debug(
                "No changes detected for entry ID {$entryId}, skipping draft creation",
                __METHOD__
            );

            return ImportResult::noChanges($entryId);
        }

        Craft::info(
            "Changes detected for entry ID {$entryId}",
            __METHOD__
        );

        Craft::debug(
            "Fields with changes: " . implode(', ', $changedFields),
            __METHOD__
        );

        // Create draft only if there are changes
        $draft = $this->createDraft($originalEntry, $userId);

        Craft::debug(
            "Applying changes to draft {$draft->draftId}",
            __METHOD__
        );

        // Apply the changed fields
        $updatedFields = $this->applyChanges($draft, $importData, $changedFields);

        Craft::debug(
            "Changes applied to draft. Updated " . count($updatedFields) . " field(s): " . implode(', ', $updatedFields),
            __METHOD__
        );

        // Save the draft
        $success = Craft::$app->getElements()->saveElement($draft);

        if (!$success) {
            $errors = $draft->getErrors();

            Craft::error(
                "Failed to save draft for entry ID {$entryId}: " . json_encode($errors),
                __METHOD__
            );

            return ImportResult::failure(
                entryId: $entryId,
                errors: $errors,
                message: 'Failed to save draft: ' . implode(', ', $draft->getErrorSummary(true))
            );
        }

        // Reload the draft to verify persistence
        $reloadedDraft = Craft::$app->getElements()->getElementById(
            $draft->id,
            null,
            $siteId,
            ['drafts' => true],
        );

        if ($reloadedDraft) {
            Craft::debug(
                "Draft {$reloadedDraft->draftId} saved and reloaded successfully for entry ID {$entryId}",
                __METHOD__
            );
        }

        Craft::debug(
            "Successfully imported entry ID {$entryId} with " . count($updatedFields) . " updated field(s)",
            __METHOD__
        );

        return ImportResult::success(
            entryId: $entryId,
            draftId: $draft->draftId,
            updatedFields: $updatedFields,
            cpEditUrl: $draft->getCpEditUrl()
        );
    }

    /**
     * Import entry from JSON string
     *
     * @param string $json The JSON string
     * @param int|null $userId User ID for draft creation
     * @return ImportResult The import result
     * @throws BadRequestHttpException If JSON is invalid
     */
    public function importFromJson(string $json, ?int $userId = null): ImportResult
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new BadRequestHttpException('Invalid JSON format: ' . json_last_error_msg());
        }

        return $this->importFromArray($data, $userId);
    }

    /**
     * Normalize import data to consistent format
     *
     * @param array $data The raw import data
     * @return array The normalized data
     */
    private function normalizeImportData(array $data): array
    {
        // If it's an array with numeric keys, get the first entry
        if (isset($data[0]) && is_array($data[0])) {
            return $data[0];
        }

        // Otherwise, assume it's already a single entry object
        return $data;
    }

    /**
     * Detect which fields have changed between original entry and import data
     *
     * Compares the original element's current values vs import data (user's edits)
     *
     * @param Element $originalEntry The current element in Craft
     * @param array $importData The import data with new values
     * @return array Array of changed field handles
     */
    private function detectChangedFields(Element $originalEntry, array $importData): array
    {
        $changedFields = [];

        Craft::info(
            "Starting change detection for entry ID {$originalEntry->id}",
            __METHOD__
        );

        // Check native entry field changes

        // Check title change
        $currentTitle = $originalEntry->title;
        $importTitle = $importData['title'] ?? null;

        if ($importTitle !== null && $importTitle !== $currentTitle) {
            $changedFields[] = 'title';
            Craft::info(
                "✓ Title CHANGED: '{$currentTitle}' → '{$importTitle}'",
                __METHOD__
            );
        }

        // Check custom fields
        $fieldLayout = $originalEntry->getFieldLayout();
        if (!$fieldLayout) {
            return $changedFields;
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            $handle = $field->handle;

            // Skip if field is not in the import data
            if (!array_key_exists($handle, $importData)) {
                Craft::debug(
                    "Field '{$handle}' not in import data, skipping",
                    __METHOD__
                );
                continue;
            }

            // Get current value from original entry
            $currentValue = $originalEntry->getFieldValue($handle);
            $importValue = $importData[$handle];

            Craft::debug(
                "Comparing field '{$handle}' - Current: " . json_encode($currentValue) . " | Import: " . json_encode($importValue),
                __METHOD__
            );

            try {
                // Get the appropriate handler for this field type
                $handler = $this->handlerRegistry->getHandler($field);

                // Export current value to normalized format for comparison
                $currentExported = $handler->export($field, $currentValue);

                // Check if the value has changed from current to import
                if ($handler->hasChanged($field, $currentExported, $importValue)) {
                    $changedFields[] = $handle;

                    Craft::debug(
                "Field (handler: " . get_class($handler) . ")",
                        __METHOD__
                    );
                } else {
                    Craft::debug(
                        "Field '{$handle}' unchanged",
                        __METHOD__
                    );
                }
            } catch (\Exception $e) {
                Craft::warning(
                    "Failed to compare field '{$handle}': " . $e->getMessage(),
                    __METHOD__
                );
            }
        }

        Craft::info(
            "Change detection complete: " . count($changedFields) . " field(s) changed: " . implode(', ', $changedFields),
            __METHOD__
        );

        return $changedFields;
    }

    /**
     * Create a draft from an element
     *
     * @param Element $entry The original element
     * @param int|null $userId User ID for draft creation
     * @return Element The created draft
     */
    private function createDraft(Element $entry, ?int $userId = null): Element
    {
        $userId = $userId ?? Craft::$app->getUser()->getId();

        /** @var Element $draft */
        $draft = Craft::$app->getDrafts()->createDraft(
            $entry,
            $userId,
            null,
            null,
            []
        );

        // Set scenario to allow all field updates
        $draft->setScenario(\craft\base\Element::SCENARIO_ESSENTIALS);

        Craft::debug(
            "Created draft {$draft->draftId} for entry ID {$entry->id}",
            __METHOD__
        );

        return $draft;
    }

    /**
     * Apply changes to draft (only changed fields)
     *
     * Uses native Craft setFieldValues() for bulk updates when possible,
     * falls back to individual handler-based updates for complex fields.
     *
     * @param Element $draft The draft to update
     * @param array $importData The full import data
     * @param array $changedFields Array of field handles that changed
     * @return array Array of updated field handles
     */
    private function applyChanges(Element $draft, array $importData, array $changedFields): array
    {
        $updatedFields = [];
        $nativeFieldUpdates = [];
        $customFieldUpdates = [];
        $nativeProperties = ['title'];

        Craft::info(
            "========== applyChanges() CALLED ========== Draft {$draft->draftId}, " . count($changedFields) . " fields: " . implode(', ', $changedFields),
            __METHOD__
        );

        Craft::info(
            "Import data keys available: " . implode(', ', array_keys($importData)),
            __METHOD__
        );

        $fieldLayout = $draft->getFieldLayout();

        // First pass: categorize fields into native properties vs custom fields
        foreach ($changedFields as $fieldHandle) {
            // Handle native element properties directly
            if (in_array($fieldHandle, $nativeProperties)) {
                $value = $importData[$fieldHandle];

                switch ($fieldHandle) {
                    case 'title':
                        $oldTitle = $draft->title;
                        $draft->title = $value;
                        $updatedFields[] = 'title';

                        Craft::debug(
                            "Updated title on draft {$draft->draftId}: '{$oldTitle}' → '{$value}'",
                            __METHOD__
                        );
                        break;
                }

                continue;
            }

            // Get the field from the layout
            $field = $fieldLayout?->getFieldByHandle($fieldHandle);

            if (!$field) {
                Craft::warning(
                    "Field '{$fieldHandle}' not found in layout for draft {$draft->draftId}",
                    __METHOD__
                );
                continue;
            }

            try {
                // Get the appropriate handler
                $handler = $this->handlerRegistry->getHandler($field);
                $value = $importData[$fieldHandle];

                // Check if handler prefers native Craft import
                if ($handler->useNativeSerialization()) {
                    // Queue for native bulk update
                    $nativeFieldUpdates[$fieldHandle] = $value;

                    Craft::debug(
                        "Queued field '{$fieldHandle}' for native bulk update",
                        __METHOD__
                    );
                } else {
                    // Transform with custom handler and queue separately
                    // Pass draft and field handle as context for Matrix fields to preserve block IDs
                    $context = [
                        'draft' => $draft,
                        'fieldHandle' => $fieldHandle,
                    ];

                    $transformedValue = $handler->import($field, $value, $context);
                    $customFieldUpdates[$fieldHandle] = $transformedValue;

                    Craft::debug(
                        "Transformed field '{$fieldHandle}' using custom handler: " . get_class($handler),
                        __METHOD__
                    );
                }
            } catch (\Exception $e) {
                Craft::error(
                    "Failed to prepare field '{$fieldHandle}' for draft {$draft->draftId}: " . $e->getMessage(),
                    __METHOD__
                );
            }
        }

        // Apply native field updates in bulk using Craft's setFieldValues
        if (!empty($nativeFieldUpdates)) {
            try {
                $draft->setFieldValues($nativeFieldUpdates);
                $updatedFields = array_merge($updatedFields, array_keys($nativeFieldUpdates));

                Craft::info(
                    "✓ Applied " . count($nativeFieldUpdates) . " native field updates in bulk: " .
                    implode(', ', array_keys($nativeFieldUpdates)),
                    __METHOD__
                );
            } catch (\Exception $e) {
                Craft::error(
                    "Failed to apply native field updates in bulk: " . $e->getMessage(),
                    __METHOD__
                );

                // Fallback: try setting each field individually
                foreach ($nativeFieldUpdates as $handle => $value) {
                    try {
                        $draft->setFieldValue($handle, $value);
                        $updatedFields[] = $handle;

                        Craft::debug(
                            "✓ Applied field '{$handle}' individually after bulk failure",
                            __METHOD__
                        );
                    } catch (\Exception $e2) {
                        Craft::error(
                            "Failed to apply field '{$handle}' individually: " . $e2->getMessage(),
                            __METHOD__
                        );
                    }
                }
            }
        }

        // Apply custom field updates individually (already transformed)
        foreach ($customFieldUpdates as $fieldHandle => $transformedValue) {
            try {
                Craft::debug(
                    "Setting field '{$fieldHandle}' with custom handler",
                    __METHOD__
                );

                // For Matrix fields and all custom fields, just use setFieldValue
                // Craft will normalize the values internally when saving
                $draft->setFieldValue($fieldHandle, $transformedValue);
                $updatedFields[] = $fieldHandle;

                // Verify the value was set
                $verifyValue = $draft->getFieldValue($fieldHandle);
                Craft::debug(
                    "Field '{$fieldHandle}' set on draft {$draft->draftId}. Type: " .
                    (is_object($verifyValue) ? get_class($verifyValue) : gettype($verifyValue)),
                    __METHOD__
                );
            } catch (\Exception $e) {
                Craft::error(
                    "Failed to apply custom field '{$fieldHandle}' on draft {$draft->draftId}: " . $e->getMessage(),
                    __METHOD__
                );
            }
        }

        Craft::info(
            "Finished applying changes. Updated " . count($updatedFields) . " field(s): " . implode(', ', $updatedFields),
            __METHOD__
        );

        return $updatedFields;
    }
}
