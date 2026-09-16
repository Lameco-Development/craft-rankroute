<?php

namespace lameco\rankroute\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use lameco\rankroute\dto\StructureCheckResult;
use lameco\rankroute\dto\TextImportResult;
use lameco\rankroute\Plugin;
use lameco\rankroute\services\text\ExtractedText;
use lameco\rankroute\services\text\TextAddress;
use lameco\rankroute\services\text\TextImportValidator;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

/**
 * Text flow import: validate everything, then write only the changed strings onto a new
 * draft, leaving every other value and every nested entry's identity untouched, and prove
 * it with the structure check before answering.
 *
 * Nested entries are written through Craft's own delta Matrix format on the draft
 * (`['entries' => [<id> => ['title' => …, 'fields' => […]]], 'sortOrder' => [<all ids>]]`),
 * nested as deep as the text sits. For an entry id listed in `entries`, Craft loads the
 * existing entry, sets only the given values on it, and, because the draft does not own
 * it primarily, saves a derivative copy (with `canonicalId` pointing at the original) for
 * the draft; every id only in `sortOrder` is kept as is. See
 * `craft\fields\Matrix::_createEntriesFromSerializedData()` and
 * `NestedElementManager::saveNestedElements()`.
 */
class TextImportService extends Component
{
    public const NOTES_PREFIX = 'rankroute:';

    /**
     * @param mixed $payload Decoded request body
     * @throws BadRequestHttpException for a malformed body
     * @throws NotFoundHttpException if the element does not exist
     */
    public function import(mixed $payload): TextImportResult
    {
        if (!is_array($payload)) {
            throw new BadRequestHttpException('The request body must be a JSON object.');
        }

        $items = $payload['items'] ?? null;

        if (!is_array($items) || !array_is_list($items)) {
            throw new BadRequestHttpException('"items" must be an array of {id, value}.');
        }

        if (!is_string($payload['fingerprint'] ?? null)) {
            throw new BadRequestHttpException('"fingerprint" is required.');
        }

        $plugin = Plugin::getInstance();
        $element = $plugin->textExportService->findElement($payload['url'] ?? null, $payload['elementId'] ?? null, $payload['siteId'] ?? null);
        $elementId = (int)$element->id;
        $siteId = (int)$element->siteId;
        $idempotencyKey = $payload['idempotencyKey'] ?? null;

        if ($idempotencyKey !== null && !self::isValidIdempotencyKey($idempotencyKey)) {
            return new TextImportResult(
                statusCode: TextImportResult::STATUS_INVALID,
                success: false,
                elementId: $elementId,
                siteId: $siteId,
                errors: [[
                    'id' => null,
                    'code' => 'invalid_idempotency_key',
                    'message' => 'idempotencyKey must be 1-64 characters of A-Z, a-z, 0-9, ".", "_", ":" or "-".',
                ]],
            );
        }

        if ($idempotencyKey === null) {
            return $this->importOnce($element, $payload['fingerprint'], $items, null);
        }

        // One import per key at a time, so a retry racing the original cannot create a
        // second draft.
        $mutex = Craft::$app->getMutex();
        $lock = "rankroute-text-import:{$elementId}:{$siteId}:{$idempotencyKey}";

        if (!$mutex->acquire($lock, 30)) {
            throw new \RuntimeException("Could not acquire the import lock for idempotency key {$idempotencyKey}.");
        }

        try {
            $existing = $this->findDraftByIdempotencyKey($element, $idempotencyKey);

            if ($existing !== null) {
                return $this->replay($element, $existing['draft'], $existing['changedItems']);
            }

            return $this->importOnce($element, $payload['fingerprint'], $items, $idempotencyKey);
        } finally {
            $mutex->release($lock);
        }
    }

    public static function isValidIdempotencyKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/', $key) === 1;
    }

    /**
     * The draft an earlier import with this idempotency key created for this element in
     * this site, with the changed items stored alongside the key in its notes.
     *
     * Draft notes: `rankroute:<key>` on the first line, `{"siteId": …, "changedItems": […]}`
     * on the second. Drafts exist in every site of the element, so the site is part of the
     * stored JSON rather than implied by the draft.
     *
     * @return array{draft: ElementInterface, changedItems: string[]}|null
     */
    public function findDraftByIdempotencyKey(ElementInterface $element, string $key): ?array
    {
        $rows = (new Query())
            ->select(['id', 'notes'])
            ->from(Table::DRAFTS)
            ->where(['canonicalId' => $element->id])
            ->andWhere(['like', 'notes', 'rankroute:%', false])
            ->orderBy(['id' => SORT_DESC])
            ->all();

        foreach ($rows as $row) {
            [$firstLine, $json] = array_pad(explode("\n", (string)$row['notes'], 2), 2, '');
            $meta = json_decode($json, true);

            if ($firstLine !== self::NOTES_PREFIX . $key || !is_array($meta) || ($meta['siteId'] ?? null) !== (int)$element->siteId) {
                continue;
            }

            $draft = $element::find()->draftId((int)$row['id'])->siteId($element->siteId)->status(null)->one();

            if ($draft !== null) {
                return ['draft' => $draft, 'changedItems' => array_values(array_filter((array)($meta['changedItems'] ?? []), 'is_string'))];
            }
        }

        return null;
    }

    /**
     * @param string[] $changedItems
     */
    public static function draftNotes(string $key, int $siteId, array $changedItems): string
    {
        return self::NOTES_PREFIX . $key . "\n" . json_encode(['siteId' => $siteId, 'changedItems' => array_values($changedItems)], JSON_UNESCAPED_SLASHES);
    }

    /**
     * A retried import: nothing is validated or written, the structure check runs again on
     * the draft the first request created.
     *
     * @param string[] $changedItems
     */
    private function replay(ElementInterface $element, ElementInterface $draft, array $changedItems): TextImportResult
    {
        $elementId = (int)$element->id;
        $siteId = (int)$element->siteId;
        $check = Plugin::getInstance()->structureCheck->check($element, $draft);

        if (!$check->passed) {
            return new TextImportResult(
                statusCode: TextImportResult::STATUS_STRUCTURE_CHECK_FAILED,
                success: false,
                elementId: $elementId,
                siteId: $siteId,
                structureCheck: $check,
                errors: [[
                    'id' => null,
                    'code' => 'structure_check_failed',
                    'message' => 'The draft of the earlier import with this idempotency key no longer passes the structure check.',
                ]],
                replayed: true,
            );
        }

        return new TextImportResult(
            statusCode: TextImportResult::STATUS_OK,
            success: true,
            elementId: $elementId,
            siteId: $siteId,
            draftId: (int)$draft->draftId,
            draftElementId: (int)$draft->id,
            cpEditUrl: $draft->getCpEditUrl(),
            changedItems: $changedItems,
            structureCheck: $check,
            replayed: true,
        );
    }

    /**
     * @param list<mixed> $items
     */
    private function importOnce(ElementInterface $element, string $submittedFingerprint, array $items, ?string $idempotencyKey): TextImportResult
    {
        $plugin = Plugin::getInstance();
        $elementId = (int)$element->id;
        $siteId = (int)$element->siteId;

        $extracted = $plugin->textExtractor->extract($element);
        $currentItems = array_map(fn(ExtractedText $text) => $text->item, $extracted);

        $fingerprint = $plugin->textFingerprint->compute($element, array_values($currentItems));

        if (!hash_equals($fingerprint, $submittedFingerprint)) {
            return new TextImportResult(
                statusCode: TextImportResult::STATUS_FINGERPRINT_MISMATCH,
                success: false,
                elementId: $elementId,
                siteId: $siteId,
                errors: [[
                    'id' => null,
                    'code' => 'fingerprint_mismatch',
                    'message' => 'The element changed since it was exported; export it again.',
                ]],
            );
        }

        $errors = (new TextImportValidator())->validate($currentItems, $items);

        if ($errors !== []) {
            return new TextImportResult(
                statusCode: TextImportResult::STATUS_INVALID,
                success: false,
                elementId: $elementId,
                siteId: $siteId,
                errors: $errors,
            );
        }

        /** @var array<string, string> $changes */
        $changes = [];

        foreach ($items as $item) {
            if ($item['value'] !== $currentItems[$item['id']]->value) {
                $changes[$item['id']] = $item['value'];
            }
        }

        if ($changes === []) {
            return new TextImportResult(
                statusCode: TextImportResult::STATUS_OK,
                success: true,
                elementId: $elementId,
                siteId: $siteId,
                structureCheck: new StructureCheckResult(true),
            );
        }

        $ownershipErrors = $this->sharedNestedEntryErrors($element, $extracted, $changes);

        if ($ownershipErrors !== []) {
            return new TextImportResult(
                statusCode: TextImportResult::STATUS_INVALID,
                success: false,
                elementId: $elementId,
                siteId: $siteId,
                errors: $ownershipErrors,
            );
        }

        return $this->writeDraft($element, $extracted, $changes, $idempotencyKey);
    }

    /**
     * Defence in depth for the delta writer: a nested entry on the path of a changed item
     * must belong to its owner (primary owner is the owner or the owner's canonical). The
     * extractor already skips shared nested entries, whose text would otherwise be written
     * into another element's content.
     *
     * @param array<string, ExtractedText> $extracted
     * @param array<string, string> $changes
     * @return list<array{id: string, code: string, message: string}>
     */
    private function sharedNestedEntryErrors(ElementInterface $element, array $extracted, array $changes): array
    {
        $errors = [];

        foreach (array_keys($changes) as $id) {
            $ownerId = (int)$element->id;

            foreach ($extracted[$id]->entryPath as $step) {
                $primaryOwnerId = (new Query())
                    ->select(['primaryOwnerId'])
                    ->from(Table::ENTRIES)
                    ->where(['id' => $step['entryId']])
                    ->scalar();

                if ((int)$primaryOwnerId !== $ownerId) {
                    $errors[] = [
                        'id' => $id,
                        'code' => 'shared_nested_entry',
                        'message' => "Item \"{$id}\" sits in nested entry {$step['entryId']}, which belongs to another element; it cannot be written from here.",
                    ];
                    break;
                }

                $ownerId = $step['entryId'];
            }
        }

        return $errors;
    }

    /**
     * @param array<string, ExtractedText> $extracted
     * @param array<string, string> $changes new value by address, in document order
     */
    private function writeDraft(ElementInterface $element, array $extracted, array $changes, ?string $idempotencyKey): TextImportResult
    {
        $elementsService = Craft::$app->getElements();
        $elementId = (int)$element->id;
        $siteId = (int)$element->siteId;
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $notes = $idempotencyKey !== null ? self::draftNotes($idempotencyKey, $siteId, array_keys($changes)) : null;
            /** @var Element $draft */
            $draft = Craft::$app->getDrafts()->createDraft($element, Craft::$app->getUser()->getId(), null, $notes);
            $draft->setScenario(Element::SCENARIO_ESSENTIALS);

            $this->applyChanges($draft, $extracted, $changes);

            if (!$elementsService->saveElement($draft)) {
                throw new \RuntimeException('The draft could not be saved: ' . implode(', ', $draft->getErrorSummary(true)));
            }

            $savedDraft = $element::find()
                ->draftId($draft->draftId)
                ->siteId($siteId)
                ->status(null)
                ->one();
            $canonical = $elementsService->getElementById($elementId, get_class($element), $siteId);

            if (!$savedDraft || !$canonical) {
                throw new \RuntimeException('The saved draft could not be read back.');
            }

            $check = Plugin::getInstance()->structureCheck->check($canonical, $savedDraft);

            if (!$check->passed) {
                $transaction->rollBack();
                Craft::error("Text import on element {$elementId} failed its structure check: " . json_encode($check->differences), __METHOD__);

                return new TextImportResult(
                    statusCode: TextImportResult::STATUS_STRUCTURE_CHECK_FAILED,
                    success: false,
                    elementId: $elementId,
                    siteId: $siteId,
                    structureCheck: $check,
                    errors: [[
                        'id' => null,
                        'code' => 'structure_check_failed',
                        'message' => 'The draft would change more than text; it was discarded.',
                    ]],
                );
            }

            $transaction->commit();
        } catch (Throwable $e) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }

            throw $e;
        }

        return new TextImportResult(
            statusCode: TextImportResult::STATUS_OK,
            success: true,
            elementId: $elementId,
            siteId: $siteId,
            draftId: (int)$savedDraft->draftId,
            draftElementId: (int)$savedDraft->id,
            cpEditUrl: $savedDraft->getCpEditUrl(),
            changedItems: array_keys($changes),
            structureCheck: $check,
        );
    }

    /**
     * @param array<string, ExtractedText> $extracted
     * @param array<string, string> $changes
     */
    private function applyChanges(ElementInterface $draft, array $extracted, array $changes): void
    {
        /** @var array<string, array<string, mixed>> $matrixDeltas top-level Matrix handle => delta value */
        $matrixDeltas = [];
        $seoValues = [];

        foreach ($changes as $id => $value) {
            $text = $extracted[$id];

            if ($text->entryPath === []) {
                if ($text->address->isSeo()) {
                    $seoValues[$text->address->leaf === TextAddress::SEO_TITLE ? 'seoTitle' : 'seoDescription'] = $value;
                } elseif ($text->address->isTitle()) {
                    $draft->title = $value;
                } else {
                    $draft->setFieldValue($text->address->leaf, $value);
                }

                continue;
            }

            $this->addToDelta($matrixDeltas, $text->entryPath, $text->address, $value);
        }

        foreach ($matrixDeltas as $handle => $delta) {
            $draft->setFieldValue($handle, $delta);
        }

        if ($seoValues !== []) {
            $this->applySeo($draft, $seoValues);
        }
    }

    /**
     * Adds one nested string to the delta tree:
     * `handle => {entries: {id: {title?, fields: {handle => value | nested delta}}}, sortOrder}`.
     * `sortOrder` always lists every existing nested entry, so none is dropped or reordered.
     *
     * @param array<string, mixed> $fields
     * @param list<array{handle: string, entryId: int, siblingIds: list<int>}> $path
     */
    private function addToDelta(array &$fields, array $path, TextAddress $address, string $value): void
    {
        $step = array_shift($path);
        $handle = $step['handle'];
        $fields[$handle] ??= ['entries' => [], 'sortOrder' => $step['siblingIds']];
        $entry = $fields[$handle]['entries'][$step['entryId']] ?? [];

        if ($path !== []) {
            $entry['fields'] ??= [];
            $this->addToDelta($entry['fields'], $path, $address, $value);
        } elseif ($address->isTitle()) {
            $entry['title'] = $value;
        } else {
            $entry['fields'][$address->leaf] = $value;
        }

        $fields[$handle]['entries'][$step['entryId']] = $entry;
    }

    /**
     * Sets only `metaGlobalVars.seoTitle`/`seoDescription` on the draft's own SEOmatic value,
     * keeping every other setting of it.
     *
     * @param array<string, string> $values
     */
    private function applySeo(ElementInterface $draft, array $values): void
    {
        $field = Plugin::getInstance()->textExtractor->seomaticField($draft);

        if ($field === null) {
            throw new \RuntimeException('The draft has no SEOmatic field.');
        }

        $bundle = $draft->getFieldValue($field->handle);

        if (!is_object($bundle) || !is_object($bundle->metaGlobalVars ?? null)) {
            throw new \RuntimeException('The SEOmatic value of the draft could not be read.');
        }

        foreach ($values as $key => $value) {
            $bundle->metaGlobalVars->{$key} = $value;
        }

        $draft->setFieldValue($field->handle, $bundle);
    }
}
