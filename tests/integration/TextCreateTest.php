<?php

namespace lameco\rankroute\tests\integration;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table as DbTable;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\fields\Matrix;
use craft\fs\Local;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Volume;
use lameco\rankroute\services\text\HtmlSkeleton;
use lameco\rankroute\services\text\PlaceholderImage;
use lameco\rankroute\services\text\SmokeRewrite;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use yii\base\Event;

/**
 * `text/create`: a new page as an unpublished draft, copied from an existing entry with
 * new texts, a new slug and the placeholder image in place of every image.
 */
final class TextCreateTest extends TextFlowFixtureTestCase
{
    private const SLUG = 'industrial-applications';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTextFlowContent();
        $this->setApiKey(self::API_KEY);
    }

    // Happy path ---------------------------------------------------------------------------

    public function testCreateCopiesTheSourceAsAnUnpublishedDraftWithTheNewTexts(): void
    {
        $export = $this->exportDocument();
        $payload = $this->createPayloadFor($export);

        $response = $this->createPage($payload);

        self::assertSame(200, $response->getStatusCode(), json_encode($response->data));
        $data = $response->data;
        self::assertSame([
            'success', 'sourceElementId', 'siteId', 'elementId', 'draftId', 'draftElementId', 'slug', 'uri',
            'enabled', 'cpEditUrl', 'changedItems', 'placeholderAssetId', 'placeholders', 'structureCheck', 'replayed',
        ], array_keys($data));
        self::assertTrue($data['success']);
        self::assertSame($this->pageId, $data['sourceElementId']);
        self::assertSame($this->primarySiteId, $data['siteId']);
        self::assertSame($data['elementId'], $data['draftElementId']);
        self::assertNotSame($this->pageId, $data['elementId']);
        self::assertSame(self::SLUG, $data['slug']);
        self::assertSame('solutions/' . self::SLUG, $data['uri']);
        self::assertFalse($data['enabled']);
        self::assertNotEmpty($data['cpEditUrl']);
        self::assertSame(array_column($export['items'], 'id'), $data['changedItems']);
        self::assertSame(['passed' => true, 'differences' => []], $data['structureCheck']);
        self::assertFalse($data['replayed']);

        $copy = $this->copy($data['draftId']);
        self::assertTrue($copy->getIsUnpublishedDraft());
        self::assertSame($this->page()->sectionId, $copy->sectionId);
        self::assertSame($this->page()->typeId, $copy->typeId);
        self::assertSame(self::SLUG, $copy->slug);
        self::assertNull($copy->postDate);
        // Never live, whatever the source's status.
        self::assertTrue($this->page()->enabled);
        self::assertFalse($copy->enabled);
        self::assertFalse($copy->getEnabledForSite());

        // Every text item of the copy is the submitted value, at the same address (Craft
        // rewrites reference tag fallbacks on save).
        $copyItems = array_column(array_map(fn($item) => $item->toArray(), $this->plugin()->textExtractor->items($copy)), 'value', 'id');
        self::assertSame(
            array_map([HtmlSkeleton::class, 'normaliseReferenceTags'], array_column($payload['items'], 'value', 'id')),
            array_map([HtmlSkeleton::class, 'normaliseReferenceTags'], $copyItems),
        );

        // The source is untouched.
        self::assertSame($export, $this->exportDocument());
        self::assertSame(1, $this->unpublishedDraftCount());
    }

    public function testTheCopyHasTheSameBlocksAndKeepsEveryNonTextValue(): void
    {
        $data = $this->createPage($this->createPayloadFor($this->exportDocument()))->data;
        $copy = $this->copy($data['draftId']);
        $page = $this->page();

        // Same blocks, types, order, count and status, at every level, but their own entries.
        self::assertSame($this->blockTree($page), $this->blockTree($copy));
        self::assertNotSame($this->nested($page, [['pageBuilder', 0]])->id, $this->nested($copy, [['pageBuilder', 0]])->id);
        self::assertSame($copy->id, $this->nested($copy, [['pageBuilder', 2]])->getPrimaryOwnerId());
        self::assertSame($this->nested($copy, [['pageBuilder', 2]])->id, $this->nested($copy, [['pageBuilder', 2], ['items', 0]])->getPrimaryOwnerId());

        // Links, buttons, options, relations and tables as in the source.
        foreach (['cta', 'topic', 'related', 'specs'] as $handle) {
            self::assertSame($this->serialized($page, $handle), $this->serialized($copy, $handle), $handle);
        }

        $buttonPath = [['pageBuilder', 2], ['items', 0], ['contentBuilder', 1], ['buttons', 0]];
        self::assertSame(
            $this->serialized($this->nested($page, $buttonPath), 'linkText'),
            $this->serialized($this->nested($copy, $buttonPath), 'linkText'),
        );
        self::assertSame('Call now', $this->nested($copy, $buttonPath)->getFieldValue('label'));

        // Text that is not an item is copied as is, empty text stays empty.
        self::assertSame('Hidden heading', $this->nested($copy, [['pageBuilder', 1]])->getFieldValue('heading'));
        self::assertSame('https://www.example.com/page', $copy->getFieldValue('website'));
        self::assertSame('', (string)$copy->getFieldValue('emptyText'));
        self::assertSame('{{ entry.intro }}', $copy->getFieldValue('seo')->metaGlobalVars->seoDescription);
    }

    public function testEveryImageBecomesThePlaceholder(): void
    {
        $data = $this->createPage($this->createPayloadFor($this->exportDocument()))->data;
        $copy = $this->copy($data['draftId']);
        $placeholder = Asset::find()->id($data['placeholderAssetId'])->one();

        self::assertInstanceOf(Asset::class, $placeholder);
        self::assertSame(['image', 'pageBuilder[2].items[0].cardImage'], $data['placeholders']);
        self::assertSame([$placeholder->id], $copy->getFieldValue('image')->ids());
        self::assertSame([$placeholder->id], $this->nested($copy, [['pageBuilder', 2], ['items', 0]])->getFieldValue('cardImage')->ids());

        // The source keeps its images.
        self::assertSame([$this->assetId], $this->page()->getFieldValue('image')->ids());
        self::assertSame([$this->otherAssetId], $this->nested($this->page(), [['pageBuilder', 2], ['items', 0]])->getFieldValue('cardImage')->ids());

        // Clearly marked, in the first volume's root folder.
        self::assertStringStartsWith('rankroute-placeholder', $placeholder->getFilename());
        self::assertSame('png', $placeholder->getExtension());
        self::assertSame(PlaceholderImage::TITLE, $placeholder->title);
        self::assertSame(PlaceholderImage::TITLE, $placeholder->alt);
        self::assertSame('images', $placeholder->getVolume()->handle);
        self::assertSame(Craft::$app->getAssets()->getRootFolderByVolumeId($placeholder->volumeId)->id, $placeholder->folderId);
    }

    public function testThePlaceholderIsCreatedOnceAndReused(): void
    {
        $export = $this->exportDocument();

        $first = $this->createPage($this->createPayloadFor($export, 'first-new-page'))->data;
        $second = $this->createPage($this->createPayloadFor($export, 'second-new-page'))->data;

        self::assertSame($first['placeholderAssetId'], $second['placeholderAssetId']);
        self::assertSame(1, $this->placeholderCount());
    }

    public function testNoPlaceholderIsCreatedWhenTheSourceHasNoImages(): void
    {
        $page = $this->page();
        $page->setFieldValue('image', []);
        self::assertTrue(Craft::$app->getElements()->saveElement($page));
        $card = $this->nested($this->page(), [['pageBuilder', 2], ['items', 0]]);
        $card->setFieldValue('cardImage', []);
        self::assertTrue(Craft::$app->getElements()->saveElement($card));

        $response = $this->createPage($this->createPayloadFor($this->exportDocument()));

        self::assertSame(200, $response->getStatusCode(), json_encode($response->data));
        self::assertNull($response->data['placeholderAssetId']);
        self::assertSame([], $response->data['placeholders']);
        self::assertSame(0, $this->placeholderCount());
    }

    public function testAnInlineImageInAnHtmlItemBecomesThePlaceholder(): void
    {
        $page = $this->page();
        $page->setFieldValue('headerTitle', '<p>Welcome to our applications</p><p><img src="{asset:' . $this->assetId . ':url||https://rankroute.test/assets/hero.txt}" alt="Hero"></p>');
        self::assertTrue(Craft::$app->getElements()->saveElement($page));

        $export = $this->exportDocument();
        $response = $this->createPage($this->createPayloadFor($export));

        self::assertSame(200, $response->getStatusCode(), json_encode($response->data));
        $placeholderId = $response->data['placeholderAssetId'];
        self::assertContains('headerTitle', $response->data['placeholders']);

        $html = (string)$this->copy($response->data['draftId'])->getFieldValue('headerTitle')->getRawContent();
        self::assertStringContainsString('{asset:' . $placeholderId . ':url', $html);
        self::assertStringNotContainsString('{asset:' . $this->assetId . ':', $html);
        self::assertStringContainsString(SmokeRewrite::MARK, $html);
        self::assertTrue($response->data['structureCheck']['passed']);
    }

    public function testTheConfiguredPlaceholderVolumeIsUsed(): void
    {
        $fs = new Local(['name' => 'Placeholder files', 'handle' => 'placeholderFiles', 'path' => dirname(__DIR__) . '/_craft/storage/test-placeholders', 'hasUrls' => true, 'url' => 'https://rankroute.test/placeholders/']);
        self::assertTrue(Craft::$app->getFs()->saveFilesystem($fs));
        $volume = new Volume(['name' => 'Placeholders', 'handle' => 'placeholders']);
        $volume->setFsHandle('placeholderFiles');
        self::assertTrue(Craft::$app->getVolumes()->saveVolume($volume));
        $this->plugin()->placeholderImage->volume = 'placeholders';

        $data = $this->createPage($this->createPayloadFor($this->exportDocument()))->data;

        self::assertSame('placeholders', Asset::find()->id($data['placeholderAssetId'])->one()->getVolume()->handle);
    }

    public function testAnUnknownPlaceholderVolumeCreatesNothing(): void
    {
        $this->plugin()->placeholderImage->volume = 'doesNotExist';

        $response = $this->createPage($this->createPayloadFor($this->exportDocument()));

        self::assertSame(500, $response->getStatusCode());
        self::assertArrayHasKey('error', $response->data);
        self::assertSame(0, $this->unpublishedDraftCount());
    }

    // Structure position and sites ------------------------------------------------------------

    public function testTheDraftStaysOutOfTheLiveStructureAndIsPublishedNextToTheSource(): void
    {
        $siblingsBefore = $this->childIds($this->parentPageId);

        $data = $this->createPage($this->createPayloadFor($this->exportDocument()))->data;

        // Front-end queries (menus) do not see the draft.
        self::assertSame($siblingsBefore, $this->childIds($this->parentPageId));
        self::assertNull(Entry::find()->uri('solutions/' . self::SLUG)->one());

        $published = Craft::$app->getDrafts()->applyDraft($this->copy($data['draftId']));

        self::assertSame($data['elementId'], $published->id);
        self::assertSame($this->parentPageId, $published->getParentId());
        self::assertSame('solutions/' . self::SLUG, $published->uri);
        self::assertSame([...$siblingsBefore, $published->id], $this->childIds($this->parentPageId));
    }

    public function testThePublishedPageIsStillDisabled(): void
    {
        $data = $this->createPage($this->createPayloadFor($this->exportDocument()))->data;

        $published = Craft::$app->getDrafts()->applyDraft($this->copy($data['draftId']));

        self::assertFalse($published->getIsDraft());
        self::assertFalse($published->enabled);
        self::assertFalse($published->getEnabledForSite());
        $publishedNl = Entry::find()->id($published->id)->siteId($this->nlSiteId)->status(null)->one();
        self::assertFalse($publishedNl->getEnabledForSite());

        // Not a live page: only a query that asks for every status finds it.
        self::assertNull(Entry::find()->uri('solutions/' . self::SLUG)->siteId($this->primarySiteId)->one());
        self::assertNull(Entry::find()->id($published->id)->siteId($this->primarySiteId)->one());
        self::assertSame($published->id, Entry::find()->id($published->id)->siteId($this->primarySiteId)->status(null)->one()?->id);
        self::assertSame('disabled', $published->getStatus());
    }

    public function testEverySiteIsDisabledAndOtherSitesKeepTheirOwnTexts(): void
    {
        $data = $this->createPage($this->createPayloadFor($this->exportDocument()))->data;

        $primary = $this->copy($data['draftId']);
        $nl = $this->copy($data['draftId'], $this->nlSiteId);

        self::assertFalse($primary->enabled);
        self::assertFalse($primary->getEnabledForSite());
        self::assertFalse($nl->getEnabledForSite());
        self::assertSame('Applications' . SmokeRewrite::MARK, $primary->title);
        self::assertSame('Toepassingen', $nl->title);
        self::assertSame('Een korte introductie', $nl->getFieldValue('intro'));
        self::assertSame(self::SLUG, $nl->slug);

        // The source's nl site is untouched.
        self::assertSame('Toepassingen', $this->page($this->nlSiteId)->title);
    }

    public function testCreateByUrlOfTheNlSiteWritesOnlyThatSite(): void
    {
        $export = $this->exportDocument(['url' => 'nl/' . $this->pageUri]);
        $payload = $this->createPayloadFor($export);
        unset($payload['sourceElementId'], $payload['siteId']);
        $response = $this->createPage([...$payload, 'url' => 'https://rankroute.test/nl/' . $this->pageUri]);

        self::assertSame(200, $response->getStatusCode(), json_encode($response->data));
        self::assertSame($this->nlSiteId, $response->data['siteId']);
        self::assertSame('solutions/' . self::SLUG, $response->data['uri']);

        $nl = $this->copy($response->data['draftId'], $this->nlSiteId);
        $primary = $this->copy($response->data['draftId'], $this->primarySiteId);
        self::assertSame('Toepassingen' . SmokeRewrite::MARK, $nl->title);
        self::assertFalse($nl->getEnabledForSite());
        self::assertSame('Applications', $primary->title);
        self::assertFalse($primary->getEnabledForSite());
    }

    // Slug --------------------------------------------------------------------------------------

    public function testASlugTakenByALivePageIsAConflict(): void
    {
        // A live sibling under the same parent, next to the source itself.
        $contact = Entry::find()->id($this->relatedEntryId)->one();
        $contact->setParentId($this->parentPageId);
        $contact->slug = 'contact-us';
        self::assertTrue(Craft::$app->getElements()->saveElement($contact));
        $export = $this->exportDocument();

        foreach (['applications', 'contact-us'] as $slug) {
            $response = $this->createPage($this->createPayloadFor($export, $slug));

            self::assertSame(409, $response->getStatusCode(), $slug . ': ' . json_encode($response->data));
            self::assertSame([['id' => null, 'code' => 'slug_taken']], $this->errorCodes($response->data));
        }

        self::assertSame(0, $this->unpublishedDraftCount());
    }

    public function testASlugTakenByAnotherNewPageDraftIsAConflict(): void
    {
        $export = $this->exportDocument();
        self::assertSame(200, $this->createPage($this->createPayloadFor($export))->getStatusCode());

        $response = $this->createPage($this->createPayloadFor($export));

        self::assertSame(409, $response->getStatusCode(), json_encode($response->data));
        self::assertSame('slug_taken', $response->data['errors'][0]['code']);
        self::assertSame(1, $this->unpublishedDraftCount());
    }

    public function testInvalidSlugsAreRejected(): void
    {
        $export = $this->exportDocument();

        foreach (['', 'Not Normalised', 'a/b', 'a  b', str_repeat('a', 256), '__temp_abc'] as $slug) {
            $response = $this->createPage($this->createPayloadFor($export, $slug));

            self::assertSame(422, $response->getStatusCode(), $slug);
            self::assertSame([['id' => null, 'code' => 'invalid_slug']], $this->errorCodes($response->data), $slug);
        }

        self::assertSame(0, $this->unpublishedDraftCount());
    }

    // Rejections ------------------------------------------------------------------------------

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    #[DataProviderExternal(TextFlowTest::class, 'rejectionProvider')]
    public function testInvalidItemsAreRejectedAndCreateNothing(callable $mutate, string $code, ?string $id): void
    {
        $export = $this->exportDocument();
        $payload = $mutate($this->createPayloadFor($export));

        $response = $this->createPage($payload);

        self::assertSame(422, $response->getStatusCode(), json_encode($response->data));
        self::assertSame(['success', 'errors'], array_keys($response->data));
        self::assertContains(['id' => $id, 'code' => $code], $this->errorCodes($response->data));
        self::assertSame(0, $this->unpublishedDraftCount());
        self::assertSame(0, $this->placeholderCount());
    }

    public function testAStaleFingerprintIsAConflict(): void
    {
        $export = $this->exportDocument();

        $page = $this->page();
        $page->setFieldValue('topic', 'guides');
        self::assertTrue(Craft::$app->getElements()->saveElement($page));

        $response = $this->createPage($this->createPayloadFor($export));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('fingerprint_mismatch', $response->data['errors'][0]['code']);
        self::assertSame(0, $this->unpublishedDraftCount());
    }

    public function testMalformedBodiesAreBadRequestsAndUnknownSourcesNotFound(): void
    {
        foreach (['', 'not json', '[]', '{"sourceElementId": 1, "fingerprint": "x", "slug": "x"}', '{"sourceElementId": 1, "items": [], "slug": "x"}', '{"sourceElementId": 1, "items": [], "fingerprint": "x"}'] as $body) {
            $response = $this->textAction('create', rawBody: $body);
            self::assertSame(400, $response->getStatusCode(), $body);
            self::assertArrayHasKey('error', $response->data, $body);
        }

        $response = $this->createPage(['sourceElementId' => 999999, 'fingerprint' => 'sha256:x', 'slug' => 'x', 'items' => []]);
        self::assertSame(404, $response->getStatusCode());

        $nested = $this->nested($this->page(), [['pageBuilder', 0]]);
        self::assertSame(404, $this->createPage(['sourceElementId' => $nested->id, 'fingerprint' => 'sha256:x', 'slug' => 'x', 'items' => []])->getStatusCode());
    }

    public function testASingleCannotBeCopied(): void
    {
        $single = new Section(['name' => 'About', 'handle' => 'about', 'type' => Section::TYPE_SINGLE]);
        $single->setEntryTypes([Craft::$app->getEntries()->getEntryTypeByHandle('page')]);
        $single->setSiteSettings([
            new Section_SiteSettings(['siteId' => $this->primarySiteId, 'hasUrls' => true, 'uriFormat' => 'about']),
        ]);
        self::assertTrue(Craft::$app->getEntries()->saveSection($single));
        $entry = Entry::find()->section('about')->status(null)->one();

        $response = $this->createPage([
            'sourceElementId' => $entry->id,
            'fingerprint' => 'sha256:x',
            'slug' => 'about-copy',
            'items' => [],
        ]);

        self::assertSame(422, $response->getStatusCode(), json_encode($response->data));
        self::assertSame([['id' => null, 'code' => 'unsupported_element']], $this->errorCodes($response->data));
    }

    public function testTheCreateIsRolledBackWhenTheStructureCheckFails(): void
    {
        $export = $this->exportDocument();

        Event::on(Entry::class, Entry::EVENT_BEFORE_SAVE, function(ModelEvent $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            if ($entry->getIsUnpublishedDraft() && $entry->sectionId === $this->page()->sectionId) {
                $entry->setFieldValue('topic', 'guides');
            }
        });

        $response = $this->createPage($this->createPayloadFor($export));

        self::assertSame(500, $response->getStatusCode(), json_encode($response->data));
        self::assertSame('structure_check_failed', $response->data['errors'][0]['code']);
        self::assertContains('fields.topic.value', array_column($response->data['structureCheck']['differences'], 'path'));
        self::assertSame(0, $this->unpublishedDraftCount());
    }

    // Idempotency -----------------------------------------------------------------------------

    public function testRetryWithTheSameIdempotencyKeyReplaysTheFirstCreate(): void
    {
        $payload = [...$this->createPayloadFor($this->exportDocument()), 'idempotencyKey' => 'gap-12'];

        $first = $this->createPage($payload);
        self::assertSame(200, $first->getStatusCode(), json_encode($first->data));

        // Answered from the stored draft, even with a stale payload and another slug.
        $retry = $this->createPage([...$payload, 'fingerprint' => 'sha256:stale', 'slug' => 'something-else', 'items' => []]);

        self::assertSame(200, $retry->getStatusCode(), json_encode($retry->data));
        self::assertTrue($retry->data['replayed']);
        self::assertSame(
            array_diff_key($first->data, ['replayed' => true, 'cpEditUrl' => true]),
            array_diff_key($retry->data, ['replayed' => true, 'cpEditUrl' => true]),
        );
        self::assertSame(1, $this->unpublishedDraftCount());
    }

    public function testADifferentIdempotencyKeyCreatesAnotherPage(): void
    {
        $export = $this->exportDocument();

        $first = $this->createPage([...$this->createPayloadFor($export, 'first-new-page'), 'idempotencyKey' => 'gap-1']);
        $second = $this->createPage([...$this->createPayloadFor($export, 'second-new-page'), 'idempotencyKey' => 'gap-2']);

        self::assertFalse($second->data['replayed']);
        self::assertNotSame($first->data['draftId'], $second->data['draftId']);
        self::assertSame(2, $this->unpublishedDraftCount());
    }

    public function testAnImportKeyIsNotACreateKey(): void
    {
        $export = $this->exportDocument();
        $import = $this->importDocument([...$this->payloadFor($export, ['intro' => 'Changed']), 'idempotencyKey' => 'shared-key']);
        self::assertSame(200, $import->getStatusCode());

        $create = $this->createPage([...$this->createPayloadFor($export), 'idempotencyKey' => 'shared-key']);

        self::assertSame(200, $create->getStatusCode(), json_encode($create->data));
        self::assertFalse($create->data['replayed']);
        self::assertNotSame($import->data['draftId'], $create->data['draftId']);
    }

    // Verify ------------------------------------------------------------------------------------

    public function testVerifyPassesForACreatedDraft(): void
    {
        $data = $this->createPage($this->createPayloadFor($this->exportDocument()))->data;

        $response = $this->textAction('verify', ['draftId' => $data['draftId']]);

        self::assertSame(200, $response->getStatusCode(), json_encode($response->data));
        self::assertSame([
            'success' => true,
            'elementId' => $data['elementId'],
            'sourceElementId' => $this->pageId,
            'siteId' => $this->primarySiteId,
            'draftId' => $data['draftId'],
            'draftElementId' => $data['elementId'],
            'structureCheck' => ['passed' => true, 'differences' => []],
        ], $response->data);
    }

    public function testVerifyFailsWhenAButtonLinkOrImageChangesOnTheCreatedDraft(): void
    {
        $data = $this->createPage($this->createPayloadFor($this->exportDocument()))->data;
        $copy = $this->copy($data['draftId']);

        $button = $this->nested($copy, [['pageBuilder', 2], ['items', 0], ['contentBuilder', 1], ['buttons', 0]]);
        $button->setFieldValue('linkText', ['type' => 'url', 'value' => 'https://example.com/hijacked', 'label' => 'Contact']);
        self::assertTrue(Craft::$app->getElements()->saveElement($button));
        $copy = $this->copy($data['draftId']);
        $copy->setFieldValue('image', [$this->assetId]);
        self::assertTrue(Craft::$app->getElements()->saveElement($copy));

        $response = $this->textAction('verify', ['draftId' => $data['draftId']]);

        self::assertSame(200, $response->getStatusCode(), json_encode($response->data));
        self::assertFalse($response->data['structureCheck']['passed']);
        $paths = array_column($response->data['structureCheck']['differences'], 'path');
        self::assertContains('fields.image.value[0]', $paths);
        self::assertContains('fields.pageBuilder.entries[2].fields.items.entries[0].fields.contentBuilder.entries[1].fields.buttons.entries[0].fields.linkText.value.value', $paths);
    }

    public function testVerifyOfAnUnpublishedDraftNotMadeByCreateIsNotFound(): void
    {
        $draft = Craft::$app->getElements()->duplicateElement($this->page(), ['slug' => 'manual-copy'], false, true);

        self::assertSame(404, $this->textAction('verify', ['draftId' => $draft->draftId])->getStatusCode());
    }

    // Helpers -----------------------------------------------------------------------------------

    /**
     * A create payload for an export with every item rewritten deterministically.
     *
     * @param array<string, mixed> $export
     * @return array<string, mixed>
     */
    private function createPayloadFor(array $export, string $slug = self::SLUG): array
    {
        return [
            'sourceElementId' => $export['element']['id'],
            'siteId' => $export['element']['siteId'],
            'fingerprint' => $export['fingerprint'],
            'slug' => $slug,
            'items' => array_map(
                fn(array $item) => [
                    'id' => $item['id'],
                    'value' => $item['type'] === 'html'
                        ? SmokeRewrite::html($item['value'])
                        : SmokeRewrite::plain($item['value'], $item['maxLength']),
                ],
                $export['items'],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createPage(array $payload): \yii\web\Response
    {
        return $this->textAction('create', rawBody: (string)json_encode($payload));
    }

    private function copy(int $draftId, ?int $siteId = null): Entry
    {
        return $this->draft($draftId, $siteId);
    }

    private function unpublishedDraftCount(): int
    {
        return (int)(new Query())->from(DbTable::DRAFTS)->where(['canonicalId' => null])->count();
    }

    private function placeholderCount(): int
    {
        return (int)Asset::find()->filename('rankroute-placeholder*')->count();
    }

    /**
     * @return list<int>
     */
    private function childIds(int $parentId): array
    {
        return array_map('intval', Entry::find()->descendantOf($parentId)->descendantDist(1)->status(null)->ids());
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{id: string|null, code: string}>
     */
    private function errorCodes(array $data): array
    {
        return array_map(fn(array $error) => ['id' => $error['id'], 'code' => $error['code']], $data['errors'] ?? []);
    }

    private function serialized(ElementInterface $element, string $handle): mixed
    {
        $field = $element->getFieldLayout()->getFieldByHandle($handle);

        return json_decode((string)json_encode($field->serializeValue($element->getFieldValue($handle), $element)), true);
    }

    /**
     * Nested entries as `{type, enabled, children}` trees, without ids.
     *
     * @return list<array<string, mixed>>
     */
    private function blockTree(ElementInterface $owner): array
    {
        $tree = [];

        foreach ($owner->getFieldLayout()->getCustomFields() as $field) {
            if (!$field instanceof Matrix) {
                continue;
            }

            foreach (Entry::find()->fieldId($field->id)->ownerId($owner->id)->siteId($owner->siteId)->status(null)->all() as $entry) {
                $tree[] = [
                    'field' => $field->handle,
                    'type' => $entry->getType()->handle,
                    'enabled' => $entry->enabled,
                    'children' => $this->blockTree($entry),
                ];
            }
        }

        return $tree;
    }
}
