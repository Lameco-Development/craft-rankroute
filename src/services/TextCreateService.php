<?php

namespace lameco\rankroute\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\models\Section;
use lameco\rankroute\dto\StructureCheckResult;
use lameco\rankroute\dto\TextCreateResult;
use lameco\rankroute\dto\TextItem;
use lameco\rankroute\Plugin;
use lameco\rankroute\services\text\ExtractedText;
use lameco\rankroute\services\text\PlaceholderImage;
use lameco\rankroute\services\text\TextImportValidator;
use lameco\rankroute\services\text\TextWriter;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

/**
 * `text/create`: a new page as an unpublished draft, copied from an existing entry (the
 * source) with new text in every text item, a new slug, and the placeholder image in place
 * of every image. Everything else (blocks, buttons, links, forms, options) is the source's.
 *
 * The items are validated exactly like a text import against the source's export. The copy
 * is Craft's own "duplicate as draft"; the strings and placeholders are written onto it
 * with the text flow writer, and the structure check compares copy and source in copy mode
 * before anything is committed. See ADR 0004.
 */
class TextCreateService extends Component
{
    public const NOTES_FLOW = 'create';

    /**
     * @param mixed $payload Decoded request body
     * @throws BadRequestHttpException for a malformed body
     * @throws NotFoundHttpException if the source does not exist
     */
    public function create(mixed $payload): TextCreateResult
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

        if (!is_string($payload['slug'] ?? null)) {
            throw new BadRequestHttpException('"slug" is required.');
        }

        $source = Plugin::getInstance()->textExportService->findElement(
            $payload['url'] ?? null,
            $payload['sourceElementId'] ?? null,
            $payload['siteId'] ?? null,
        );
        $sourceId = (int)$source->id;
        $siteId = (int)$source->siteId;
        $slug = $payload['slug'];
        $idempotencyKey = $payload['idempotencyKey'] ?? null;

        if (!self::isCopyable($source)) {
            return TextCreateResult::error(TextCreateResult::STATUS_INVALID, $sourceId, $siteId, 'unsupported_element', 'Only an entry of a channel or structure section can be the source of a new page.');
        }

        if ($idempotencyKey !== null && !TextImportService::isValidIdempotencyKey($idempotencyKey)) {
            return TextCreateResult::error(TextCreateResult::STATUS_INVALID, $sourceId, $siteId, 'invalid_idempotency_key', 'idempotencyKey must be 1-64 characters of A-Z, a-z, 0-9, ".", "_", ":" or "-".');
        }

        if (!self::isValidSlug($slug)) {
            return TextCreateResult::error(TextCreateResult::STATUS_INVALID, $sourceId, $siteId, 'invalid_slug', 'slug must be a normalised Craft slug of at most 255 characters.');
        }

        // A retry racing the original, or two requests for the same slug, must not create
        // two pages.
        $locks = ["rankroute-text-create-slug:{$siteId}:{$slug}"];

        if ($idempotencyKey !== null) {
            array_unshift($locks, "rankroute-text-create:{$sourceId}:{$siteId}:{$idempotencyKey}");
        }

        $mutex = Craft::$app->getMutex();
        $acquired = [];

        try {
            foreach ($locks as $lock) {
                if (!$mutex->acquire($lock, 30)) {
                    throw new \RuntimeException("Could not acquire the create lock {$lock}.");
                }

                $acquired[] = $lock;
            }

            if ($idempotencyKey !== null && ($existing = $this->findCreatedDraft($source, $idempotencyKey)) !== null) {
                return $this->replay($source, $existing['draft'], $existing['meta']);
            }

            return $this->createOnce($source, $payload['fingerprint'], $slug, $items, $idempotencyKey);
        } finally {
            foreach ($acquired as $lock) {
                $mutex->release($lock);
            }
        }
    }

    /**
     * An entry of a channel or structure section; not a nested entry, not a single.
     */
    public static function isCopyable(ElementInterface $element): bool
    {
        if (!$element instanceof Entry || $element->fieldId !== null) {
            return false;
        }

        $section = $element->getSection();

        return $section !== null && $section->type !== Section::TYPE_SINGLE;
    }

    public static function isValidSlug(mixed $slug): bool
    {
        return is_string($slug)
            && $slug !== ''
            && mb_strlen($slug) <= 255
            && $slug !== Element::HOMEPAGE_URI
            && !ElementHelper::isTempSlug($slug)
            && ElementHelper::normalizeSlug($slug) === $slug;
    }

    /**
     * Draft notes of a new page: `rankroute:<key>` (or `rankroute:` without a key) on the
     * first line, the JSON metadata on the second.
     *
     * @param array<string, mixed> $meta
     */
    public static function draftNotes(?string $key, array $meta): string
    {
        return TextImportService::NOTES_PREFIX . ($key ?? '') . "\n"
            . json_encode(['flow' => self::NOTES_FLOW, ...$meta], JSON_UNESCAPED_SLASHES);
    }

    /**
     * The key and metadata from the notes of a new page, or null for any other notes.
     *
     * @return array{key: string|null, meta: array<string, mixed>}|null
     */
    public static function parseDraftNotes(?string $notes): ?array
    {
        [$firstLine, $json] = array_pad(explode("\n", (string)$notes, 2), 2, '');

        if (!str_starts_with($firstLine, TextImportService::NOTES_PREFIX)) {
            return null;
        }

        $meta = json_decode($json, true);

        if (!is_array($meta) || ($meta['flow'] ?? null) !== self::NOTES_FLOW || !is_int($meta['sourceElementId'] ?? null) || !is_int($meta['siteId'] ?? null)) {
            return null;
        }

        $key = substr($firstLine, strlen(TextImportService::NOTES_PREFIX));

        return ['key' => $key !== '' ? $key : null, 'meta' => $meta];
    }

    /**
     * The new page an earlier create with this key made from this source in this site.
     *
     * @return array{draft: Entry, meta: array<string, mixed>}|null
     */
    public function findCreatedDraft(ElementInterface $source, string $key): ?array
    {
        $rows = (new Query())
            ->select(['id', 'notes'])
            ->from(Table::DRAFTS)
            ->where(['canonicalId' => null])
            ->andWhere(['like', 'notes', TextImportService::NOTES_PREFIX . $key . "\n%", false])
            ->orderBy(['id' => SORT_DESC])
            ->all();

        foreach ($rows as $row) {
            $parsed = self::parseDraftNotes($row['notes']);

            if ($parsed === null || $parsed['key'] !== $key || $parsed['meta']['sourceElementId'] !== (int)$source->id || $parsed['meta']['siteId'] !== (int)$source->siteId) {
                continue;
            }

            $draft = Entry::find()->draftId((int)$row['id'])->siteId($source->siteId)->status(null)->one();

            if ($draft !== null) {
                return ['draft' => $draft, 'meta' => $parsed['meta']];
            }
        }

        return null;
    }

    /**
     * The structure check of a new page against its source, for `text/verify`: null when
     * the unpublished draft was not made by `text/create` or its source is gone. Without
     * an explicit site the draft is checked in the site it was created for.
     *
     * @return array{draft: ElementInterface, source: ElementInterface, check: StructureCheckResult}|null
     */
    public function verify(ElementInterface $draft, bool $siteGiven): ?array
    {
        $notes = (new Query())->select(['notes'])->from(Table::DRAFTS)->where(['id' => $draft->draftId])->scalar();
        $parsed = self::parseDraftNotes(is_string($notes) ? $notes : null);

        if ($parsed === null) {
            return null;
        }

        if (!$siteGiven && (int)$draft->siteId !== $parsed['meta']['siteId']) {
            $draft = $draft::find()->draftId($draft->draftId)->siteId($parsed['meta']['siteId'])->status(null)->one();

            if ($draft === null) {
                return null;
            }
        }

        $source = Craft::$app->getElements()->getElementById($parsed['meta']['sourceElementId'], get_class($draft), $draft->siteId);

        if ($source === null) {
            return null;
        }

        $placeholderId = $parsed['meta']['placeholderAssetId'] ?? null;

        return [
            'draft' => $draft,
            'source' => $source,
            'check' => Plugin::getInstance()->structureCheck->checkCopy($source, $draft, is_int($placeholderId) ? $placeholderId : null),
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function replay(ElementInterface $source, Entry $draft, array $meta): TextCreateResult
    {
        $placeholderId = is_int($meta['placeholderAssetId'] ?? null) ? $meta['placeholderAssetId'] : null;
        $check = Plugin::getInstance()->structureCheck->checkCopy($source, $draft, $placeholderId);

        if (!$check->passed) {
            return TextCreateResult::error(
                TextCreateResult::STATUS_STRUCTURE_CHECK_FAILED,
                (int)$source->id,
                (int)$source->siteId,
                'structure_check_failed',
                'The new page of the earlier create with this idempotency key no longer passes the structure check.',
                $check,
                replayed: true,
            );
        }

        return $this->success($source, $draft, self::strings($meta['changedItems'] ?? []), $placeholderId, self::strings($meta['placeholders'] ?? []), $check, true);
    }

    /**
     * @param list<mixed> $items
     */
    private function createOnce(ElementInterface $source, string $submittedFingerprint, string $slug, array $items, ?string $idempotencyKey): TextCreateResult
    {
        $plugin = Plugin::getInstance();
        $sourceId = (int)$source->id;
        $siteId = (int)$source->siteId;

        $extracted = $plugin->textExtractor->extract($source);
        $currentItems = array_map(fn(ExtractedText $text) => $text->item, $extracted);

        if (!hash_equals($plugin->textFingerprint->compute($source, array_values($currentItems)), $submittedFingerprint)) {
            return TextCreateResult::error(TextCreateResult::STATUS_CONFLICT, $sourceId, $siteId, 'fingerprint_mismatch', 'The source changed since it was exported; export it again.');
        }

        $errors = (new TextImportValidator())->validate($currentItems, $items);

        if ($errors !== []) {
            return new TextCreateResult(
                statusCode: TextCreateResult::STATUS_INVALID,
                success: false,
                sourceElementId: $sourceId,
                siteId: $siteId,
                errors: $errors,
            );
        }

        /** @var array<string, string> $values new value by address, in document order */
        $values = [];

        foreach (array_keys($currentItems) as $id) {
            foreach ($items as $item) {
                if ($item['id'] === $id) {
                    $values[$id] = $item['value'];
                }
            }
        }

        // Outside the transaction: the placeholder is shared by every new page, and a
        // rolled back asset would leave its file behind in the volume.
        $placeholder = $this->needsPlaceholder($source, $currentItems) ? $plugin->placeholderImage->findOrCreate() : null;

        return $this->writeCopy($source, $slug, $currentItems, $values, $placeholder, $idempotencyKey);
    }

    /**
     * @param array<string, TextItem> $currentItems
     */
    private function needsPlaceholder(ElementInterface $source, array $currentItems): bool
    {
        if (Plugin::getInstance()->textExtractor->assetFields($source) !== []) {
            return true;
        }

        foreach ($currentItems as $item) {
            if ($item->type === TextItem::TYPE_HTML && PlaceholderImage::hasAssetReferences($item->value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, TextItem> $currentItems
     * @param array<string, string> $values
     */
    private function writeCopy(ElementInterface $source, string $slug, array $currentItems, array $values, ?Asset $placeholder, ?string $idempotencyKey): TextCreateResult
    {
        $plugin = Plugin::getInstance();
        $elementsService = Craft::$app->getElements();
        $sourceId = (int)$source->id;
        $siteId = (int)$source->siteId;
        $parentId = $source instanceof Entry ? $source->getParentId() : null;

        // Every other site of the copy: disabled there, the source's texts in that language.
        $siteAttributes = [];

        foreach (Craft::$app->getSites()->getAllSiteIds() as $otherSiteId) {
            if ((int)$otherSiteId !== $siteId) {
                $siteAttributes[(int)$otherSiteId] = ['enabledForSite' => false];
            }
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $duplicate = $elementsService->duplicateElement($source, [
                'slug' => $slug,
                'postDate' => null,
                'expiryDate' => null,
                'siteAttributes' => $siteAttributes,
            ], placeInStructure: false, asUnpublishedDraft: true);

            /** @var Entry|null $copy */
            $copy = Entry::find()->draftId($duplicate->draftId)->siteId($siteId)->status(null)->one();

            if ($copy === null) {
                throw new \RuntimeException('The copy could not be read back.');
            }

            // Craft suffixes a slug whose URI a live element already has.
            if ($copy->slug !== $slug || $this->uriTakenByAnotherNewPage($copy)) {
                $transaction->rollBack();

                return TextCreateResult::error(TextCreateResult::STATUS_CONFLICT, $sourceId, $siteId, 'slug_taken', "The slug \"{$slug}\" gives a URI that another page in this site already has.");
            }

            $copyExtracted = $plugin->textExtractor->extract($copy);

            if (array_keys($copyExtracted) !== array_keys($currentItems)) {
                throw new \RuntimeException('The copy does not have the text items of its source: ' . json_encode(array_keys($copyExtracted)));
            }

            $writes = [];
            $fieldPlaceholders = [];
            $itemPlaceholders = [];

            foreach ($values as $id => $value) {
                if ($placeholder !== null && $currentItems[$id]->type === TextItem::TYPE_HTML && PlaceholderImage::hasAssetReferences($value)) {
                    $value = PlaceholderImage::replaceAssetReferences($value, (int)$placeholder->id, $placeholder->getUrl());
                    $itemPlaceholders[] = $id;
                }

                $writes[] = ['entryPath' => $copyExtracted[$id]->entryPath, 'address' => $copyExtracted[$id]->address, 'value' => $value];
            }

            if ($placeholder !== null) {
                foreach ($plugin->textExtractor->assetFields($copy) as $assetField) {
                    $writes[] = ['entryPath' => $assetField['entryPath'], 'address' => $assetField['address'], 'value' => [(int)$placeholder->id]];
                    $fieldPlaceholders[] = $assetField['address']->toString();
                }
            }

            $placeholders = [...$fieldPlaceholders, ...$itemPlaceholders];
            (new TextWriter())->apply($copy, $writes);

            // Next to the source: same parent, placed when the draft is saved. Drafts are
            // left out of every element query, so no menu shows it before it is published.
            $copy->setParentId($parentId ?: false);
            $copy->setScenario(Element::SCENARIO_ESSENTIALS);

            if (!$elementsService->saveElement($copy)) {
                throw new \RuntimeException('The copy could not be saved: ' . implode(', ', $copy->getErrorSummary(true)));
            }

            $changedItems = array_keys(array_filter($values, fn(string $value, string $id) => $value !== $currentItems[$id]->value, ARRAY_FILTER_USE_BOTH));

            Db::update(Table::DRAFTS, [
                'notes' => self::draftNotes($idempotencyKey, [
                    'sourceElementId' => $sourceId,
                    'siteId' => $siteId,
                    'slug' => $slug,
                    'placeholderAssetId' => $placeholder?->id !== null ? (int)$placeholder->id : null,
                    'changedItems' => $changedItems,
                    'placeholders' => $placeholders,
                ]),
            ], ['id' => $copy->draftId]);

            $savedCopy = Entry::find()->draftId($copy->draftId)->siteId($siteId)->status(null)->one();
            $freshSource = $elementsService->getElementById($sourceId, get_class($source), $siteId);

            if ($savedCopy === null || $freshSource === null) {
                throw new \RuntimeException('The saved copy could not be read back.');
            }

            $check = $plugin->structureCheck->checkCopy($freshSource, $savedCopy, $placeholder?->id !== null ? (int)$placeholder->id : null);

            if (!$check->passed) {
                $transaction->rollBack();
                Craft::error("Creating a new page from element {$sourceId} failed its structure check: " . json_encode($check->differences), __METHOD__);

                return TextCreateResult::error(
                    TextCreateResult::STATUS_STRUCTURE_CHECK_FAILED,
                    $sourceId,
                    $siteId,
                    'structure_check_failed',
                    'The new page would differ from its source in more than text, slug and images; it was discarded.',
                    $check,
                );
            }

            $transaction->commit();
        } catch (Throwable $e) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }

            throw $e;
        }

        return $this->success($freshSource, $savedCopy, $changedItems, $placeholder?->id !== null ? (int)$placeholder->id : null, $placeholders, $check, false);
    }

    /**
     * Another unpublished draft in the same site with the same URI: most likely an earlier
     * new page for the same gap, created without an idempotency key.
     */
    private function uriTakenByAnotherNewPage(Entry $copy): bool
    {
        if ($copy->uri === null || $copy->uri === '') {
            return false;
        }

        return (new Query())
            ->from(['elements_sites' => Table::ELEMENTS_SITES])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[elements_sites.elementId]]')
            ->innerJoin(['drafts' => Table::DRAFTS], '[[drafts.id]] = [[elements.draftId]]')
            ->where([
                'elements_sites.siteId' => $copy->siteId,
                'elements_sites.uri' => $copy->uri,
                'drafts.canonicalId' => null,
                'elements.dateDeleted' => null,
            ])
            ->andWhere(['not', ['elements.id' => $copy->id]])
            ->exists();
    }

    /**
     * @param string[] $changedItems
     * @param string[] $placeholders
     */
    private function success(ElementInterface $source, Entry $copy, array $changedItems, ?int $placeholderId, array $placeholders, StructureCheckResult $check, bool $replayed): TextCreateResult
    {
        return new TextCreateResult(
            statusCode: TextCreateResult::STATUS_OK,
            success: true,
            sourceElementId: (int)$source->id,
            siteId: (int)$copy->siteId,
            elementId: (int)$copy->id,
            draftId: (int)$copy->draftId,
            slug: $copy->slug,
            uri: $copy->uri,
            cpEditUrl: $copy->getCpEditUrl(),
            changedItems: array_values($changedItems),
            placeholderAssetId: $placeholderId,
            placeholders: array_values($placeholders),
            structureCheck: $check,
            replayed: $replayed,
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return array_values(array_filter((array)$values, 'is_string'));
    }
}
