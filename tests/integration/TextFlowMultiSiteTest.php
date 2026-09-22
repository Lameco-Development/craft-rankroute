<?php

namespace lameco\rankroute\tests\integration;

use Craft;
use craft\base\Field;
use craft\base\FieldInterface;
use craft\elements\Entry;
use lameco\rankroute\services\text\HtmlSkeleton;
use lameco\rankroute\services\text\TextImportValidator;
use RuntimeException;

/**
 * The text flow on a multilingual install (the Avineon Tensing case): one page propagated
 * to three sites with their own texts and SEO meta. `en` is the primary site on
 * `https://rankroute.test/`, `nl` shares its host under `/nl/`, `de` has its own domain
 * `https://rankroute.de/`.
 *
 * Proves that a request for one site reads and writes that site only, that SEO meta is
 * handled as the literal per-site value, and that text shared between sites (translation
 * method "none", or a key that other sites share) is never offered for rewriting, since
 * writing it from one site would change every site that shares it.
 */
final class TextFlowMultiSiteTest extends TextFlowFixtureTestCase
{
    protected array $extraSites = [
        'de' => ['RankRoute DE', 'de-DE', 'https://rankroute.de/'],
    ];

    private int $deSiteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTextFlowContent();
        $this->deSiteId = $this->extraSiteIds['de'];
        $this->setApiKey(self::API_KEY);

        $this->savePage($this->nlSiteId, [
            'seo' => ['metaGlobalVars' => [
                'seoTitle' => 'Toepassingen voor de industrie',
                'seoDescription' => 'Alles over onze industriële toepassingen.',
                'siteNamePosition' => 'after',
            ]],
        ]);

        $this->savePage($this->deSiteId, [
            'title' => 'Anwendungen',
            'intro' => 'Eine kurze Einführung',
            'seo' => ['metaGlobalVars' => [
                'seoTitle' => 'Anwendungen für die Industrie',
                'seoDescription' => '',
            ]],
        ]);
    }

    // Export ---------------------------------------------------------------------------

    public function testExportOfTheNlSiteByPathOrFullUrlReturnsTheNlTextsAndSeoMeta(): void
    {
        $byPath = $this->exportDocument(['url' => 'nl/' . $this->pageUri]);
        $byUrl = $this->exportDocument(['url' => 'https://rankroute.test/nl/' . $this->pageUri]);
        $byId = $this->exportDocument(['id' => $this->pageId, 'siteId' => $this->nlSiteId]);

        self::assertSame($byPath, $byUrl);
        self::assertSame($byPath, $byId);
        self::assertSame($this->nlSiteId, $byUrl['element']['siteId']);
        self::assertSame($this->page($this->nlSiteId)->getUrl(), $byUrl['element']['url']);

        $items = array_column($byUrl['items'], 'value', 'id');
        self::assertSame('Toepassingen', $items['title']);
        self::assertSame('Een korte introductie', $items['intro']);
        self::assertSame('Toepassingen voor de industrie', $items['seo.seoTitle']);
        self::assertSame('Alles over onze industriële toepassingen.', $items['seo.seoDescription']);

        $primary = array_column($this->exportDocument()['items'], 'value', 'id');
        self::assertSame('Applications | SEO title', $primary['seo.seoTitle']);
        self::assertNotSame($byUrl['fingerprint'], $this->exportDocument()['fingerprint']);
    }

    public function testExportByFullUrlOfASiteOnItsOwnDomainReturnsThatSite(): void
    {
        $export = $this->exportDocument(['url' => 'https://rankroute.de/' . $this->pageUri]);

        self::assertSame($this->deSiteId, $export['element']['siteId']);
        self::assertSame($this->page($this->deSiteId)->getUrl(), $export['element']['url']);

        $items = array_column($export['items'], 'value', 'id');
        self::assertSame('Anwendungen', $items['title']);
        self::assertSame('Eine kurze Einführung', $items['intro']);
        self::assertSame('Anwendungen für die Industrie', $items['seo.seoTitle']);

        // A bare path carries no host, so it still means the primary site.
        self::assertSame($this->primarySiteId, $this->exportDocument(['url' => $this->pageUri])['element']['siteId']);
    }

    public function testSeoMetaIsExportedAsTheLiteralValueWithoutTheSiteName(): void
    {
        $items = array_column($this->exportDocument(['url' => 'nl/' . $this->pageUri])['items'], 'value', 'id');

        self::assertSame('Toepassingen voor de industrie', $items['seo.seoTitle']);
        self::assertStringNotContainsString('RankRoute', $items['seo.seoTitle']);
        self::assertStringNotContainsString('|', $items['seo.seoTitle']);
    }

    public function testEmptyAndInheritedSeoMetaIsNeverAnItem(): void
    {
        // de: an empty description, and a title that SEOmatic pulls from a field even
        // though a stale literal is still stored next to it.
        $this->savePage($this->deSiteId, [
            'seo' => [
                'metaGlobalVars' => ['seoTitle' => 'Stale literal title', 'seoDescription' => ''],
                'metaBundleSettings' => ['seoTitleSource' => 'fromField', 'seoTitleField' => 'intro'],
            ],
        ]);

        $export = $this->exportDocument(['url' => 'https://rankroute.de/' . $this->pageUri]);
        $ids = array_column($export['items'], 'id');

        self::assertNotContains('seo.seoTitle', $ids);
        self::assertNotContains('seo.seoDescription', $ids);

        foreach (['seo.seoTitle', 'seo.seoDescription'] as $id) {
            $payload = $this->payloadFor($export);
            $payload['items'][] = ['id' => $id, 'value' => 'Injected meta'];
            $response = $this->importDocument($payload);

            self::assertSame(422, $response->getStatusCode(), json_encode($response->data));
            self::assertSame([[$id, TextImportValidator::UNKNOWN_ID]], $this->errorCodes($response->data));
        }

        self::assertSame(0, $this->draftCount());
    }

    // Import ---------------------------------------------------------------------------

    public function testAnEmptyOrNullValueCannotOverwriteSeoMeta(): void
    {
        $export = $this->exportDocument(['url' => 'nl/' . $this->pageUri]);

        foreach (['seo.seoTitle', 'seo.seoDescription'] as $id) {
            foreach (['', '   ', null] as $value) {
                $payload = $this->payloadFor($export);
                $payload['items'] = array_map(
                    fn(array $item) => $item['id'] === $id ? ['id' => $id, 'value' => $value] : $item,
                    $payload['items'],
                );
                $response = $this->importDocument($payload);

                self::assertSame(422, $response->getStatusCode(), json_encode($response->data));
                self::assertSame([[$id, TextImportValidator::EMPTY_VALUE]], $this->errorCodes($response->data));
            }
        }

        self::assertSame(0, $this->draftCount());
        self::assertSame('Toepassingen voor de industrie', $this->seo($this->page($this->nlSiteId))['seoTitle']);
    }

    public function testImportOnTheNlSiteChangesOnlyTheNlSiteAlsoAfterApplyingTheDraft(): void
    {
        $export = $this->exportDocument(['url' => 'https://rankroute.test/nl/' . $this->pageUri]);
        $primaryBefore = $this->flatten($this->page());
        $deBefore = $this->flatten($this->page($this->deSiteId));

        $response = $this->importDocument($this->payloadFor($export, [
            'title' => 'Industriële toepassingen',
            'intro' => 'Een nieuwe introductie',
            'pageBuilder[0].heading' => 'Nieuwe kop',
            'seo.seoTitle' => 'Industriële toepassingen van Avineon',
            'seo.seoDescription' => 'Een nieuwe metaomschrijving.',
        ]));

        self::assertSame(200, $response->getStatusCode(), json_encode($response->data));
        self::assertSame($this->nlSiteId, $response->data['siteId']);
        self::assertTrue($response->data['structureCheck']['passed']);

        $nlDraft = $this->draft($response->data['draftId'], $this->nlSiteId);
        self::assertSame('Industriële toepassingen', $nlDraft->title);
        self::assertSame('Een nieuwe introductie', $nlDraft->getFieldValue('intro'));
        self::assertSame('Nieuwe kop', $this->nested($nlDraft, [['pageBuilder', 0]])->getFieldValue('heading'));
        self::assertSame(
            ['seoTitle' => 'Industriële toepassingen van Avineon', 'seoDescription' => 'Een nieuwe metaomschrijving.', 'siteNamePosition' => 'after'],
            $this->seo($nlDraft),
        );

        // The draft exists in every site; only nl differs from its canonical.
        self::assertSame($primaryBefore, $this->flatten($this->draft($response->data['draftId'], $this->primarySiteId)));
        self::assertSame($deBefore, $this->flatten($this->draft($response->data['draftId'], $this->deSiteId)));
        self::assertSame('Toepassingen voor de industrie', $this->seo($this->page($this->nlSiteId))['seoTitle']);

        // What an editor does next: publish the draft.
        Craft::$app->getDrafts()->applyDraft($nlDraft);

        $nlPage = $this->page($this->nlSiteId);
        self::assertSame('Industriële toepassingen', $nlPage->title);
        self::assertSame('Een nieuwe introductie', $nlPage->getFieldValue('intro'));
        self::assertSame('Nieuwe kop', $this->nested($nlPage, [['pageBuilder', 0]])->getFieldValue('heading'));
        self::assertSame(
            ['seoTitle' => 'Industriële toepassingen van Avineon', 'seoDescription' => 'Een nieuwe metaomschrijving.', 'siteNamePosition' => 'after'],
            $this->seo($nlPage),
        );

        self::assertSame($primaryBefore, $this->flatten($this->page()));
        self::assertSame($deBefore, $this->flatten($this->page($this->deSiteId)));
    }

    public function testImportedSeoTitleIsStoredLiterallyAndNotDuplicatedOnTheNextRound(): void
    {
        $first = $this->importDocument($this->payloadFor(
            $this->exportDocument(['url' => 'nl/' . $this->pageUri]),
            ['seo.seoTitle' => 'Toepassingen van Avineon'],
        ));
        self::assertSame(200, $first->getStatusCode(), json_encode($first->data));
        Craft::$app->getDrafts()->applyDraft($this->draft($first->data['draftId'], $this->nlSiteId));

        self::assertSame('Toepassingen van Avineon', $this->seo($this->page($this->nlSiteId))['seoTitle']);

        // A second round starts from exactly what the first one wrote.
        $items = array_column($this->exportDocument(['url' => 'nl/' . $this->pageUri])['items'], 'value', 'id');
        self::assertSame('Toepassingen van Avineon', $items['seo.seoTitle']);
    }

    // Verify ---------------------------------------------------------------------------

    public function testVerifyOnTheSecondSiteChecksThatSitesDraft(): void
    {
        $import = $this->importDocument($this->payloadFor(
            $this->exportDocument(['url' => 'https://rankroute.de/' . $this->pageUri]),
            ['intro' => 'Eine neue Einführung', 'seo.seoTitle' => 'Neue Anwendungen'],
        ));
        self::assertSame(200, $import->getStatusCode(), json_encode($import->data));
        $draftId = $import->data['draftId'];

        $response = $this->textAction('verify', ['draftId' => $draftId, 'siteId' => $this->deSiteId]);

        self::assertSame(200, $response->getStatusCode(), json_encode($response->data));
        self::assertSame([
            'success' => true,
            'elementId' => $this->pageId,
            'siteId' => $this->deSiteId,
            'draftId' => $draftId,
            'draftElementId' => $this->draft($draftId, $this->deSiteId)->id,
            'structureCheck' => ['passed' => true, 'differences' => []],
        ], $response->data);
        self::assertSame('Eine neue Einführung', $this->draft($draftId, $this->deSiteId)->getFieldValue('intro'));
    }

    // Text shared between sites --------------------------------------------------------

    public function testTextSharedWithOtherSitesIsNeverAnItem(): void
    {
        $this->shareBetweenSites();

        foreach ([$this->pageUri, 'nl/' . $this->pageUri, 'https://rankroute.de/' . $this->pageUri] as $url) {
            $ids = array_column($this->exportDocument(['url' => $url])['items'], 'id');

            self::assertNotContains('headerTitle', $ids, $url);
            self::assertNotContains('seo.seoTitle', $ids, $url);
            self::assertNotContains('seo.seoDescription', $ids, $url);
            self::assertNotContains('pageBuilder[0].heading', $ids, $url);
            self::assertContains('intro', $ids, $url);
            self::assertContains('pageBuilder[0].content', $ids, $url);
        }
    }

    public function testAValueSharedWithOtherSitesCannotBeImported(): void
    {
        $this->shareBetweenSites();
        $export = $this->exportDocument(['url' => 'nl/' . $this->pageUri]);

        foreach (['headerTitle', 'seo.seoTitle', 'pageBuilder[0].heading'] as $id) {
            $payload = $this->payloadFor($export);
            $payload['items'][] = ['id' => $id, 'value' => 'Nederlandse tekst in alle talen'];
            $response = $this->importDocument($payload);

            self::assertSame(422, $response->getStatusCode(), json_encode($response->data));
            self::assertSame([[$id, TextImportValidator::UNKNOWN_ID]], $this->errorCodes($response->data));
        }

        self::assertSame(0, $this->draftCount());
        self::assertSame('Applications | SEO title', $this->seo($this->page())['seoTitle']);
        self::assertSame('Intro heading', $this->nested($this->page(), [['pageBuilder', 0]])->getFieldValue('heading'));
    }

    public function testASiteSpecificImportLeavesTextSharedWithOtherSitesAlone(): void
    {
        $this->shareBetweenSites();
        $primaryBefore = $this->flatten($this->page());

        $response = $this->importDocument($this->payloadFor(
            $this->exportDocument(['url' => 'nl/' . $this->pageUri]),
            ['intro' => 'Een nieuwe introductie'],
        ));

        self::assertSame(200, $response->getStatusCode(), json_encode($response->data));
        $nlDraft = $this->draft($response->data['draftId'], $this->nlSiteId);
        Craft::$app->getDrafts()->applyDraft($nlDraft);

        self::assertSame('Een nieuwe introductie', $this->page($this->nlSiteId)->getFieldValue('intro'));
        // Applying resaves the other sites here, which rewrites reference tag fallbacks.
        self::assertSame($this->withoutFallbacks($primaryBefore), $this->withoutFallbacks($this->flatten($this->page())));
    }

    // Helpers --------------------------------------------------------------------------

    /**
     * Makes some text shared between the sites, the way a multilingual install can have it
     * by accident: the CKEditor `headerTitle`, the SEOmatic field and the nested `heading`
     * field switched to "not translatable". Their values stay as they are per site until
     * the next save, but from now on a save in one site writes them to every site.
     */
    private function shareBetweenSites(): void
    {
        foreach (['headerTitle', 'seo', 'heading'] as $handle) {
            $this->makeUntranslatable($this->fields[$handle]);
        }

        $this->forgetCachedLayouts();
    }

    /**
     * Field layouts keep the field instances they were built with, so a changed
     * translation method only shows in the next request. This test has to start over.
     */
    private function forgetCachedLayouts(): void
    {
        $fieldsService = Craft::$app->getFields();
        $fieldsService->refreshFields();
        (new \ReflectionProperty($fieldsService, '_layouts'))->setValue($fieldsService, null);
        $entries = Craft::$app->getEntries();
        $entries->refreshEntryTypes();
        (new \ReflectionProperty($entries, '_sections'))->setValue($entries, null);
    }

    private function makeUntranslatable(FieldInterface $field): void
    {
        /** @var Field $field */
        $field->translationMethod = Field::TRANSLATION_METHOD_NONE;

        if (!Craft::$app->getFields()->saveField($field)) {
            throw new RuntimeException("Could not save field {$field->handle}: " . implode(', ', $field->getErrorSummary(true)));
        }
    }

    /**
     * @param array<string, mixed> $values `title` and custom field values
     */
    private function savePage(int $siteId, array $values): Entry
    {
        $page = $this->page($siteId);

        if (array_key_exists('title', $values)) {
            $page->title = $values['title'];
            unset($values['title']);
        }

        $page->setFieldValues($values);

        if (!Craft::$app->getElements()->saveElement($page)) {
            throw new RuntimeException("Could not save the page in site {$siteId}: " . implode(', ', $page->getErrorSummary(true)));
        }

        return $page;
    }

    /**
     * @return array{seoTitle: mixed, seoDescription: mixed, siteNamePosition: mixed}
     */
    private function seo(Entry $element): array
    {
        $vars = $element->getFieldValue('seo')->metaGlobalVars;

        return [
            'seoTitle' => $vars->seoTitle,
            'seoDescription' => $vars->seoDescription,
            'siteNamePosition' => $vars->siteNamePosition,
        ];
    }

    /**
     * @param array<string, mixed> $flat
     * @return array<string, mixed>
     */
    private function withoutFallbacks(array $flat): array
    {
        return array_map(fn($value) => is_string($value) ? HtmlSkeleton::normaliseReferenceTags($value) : $value, $flat);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{0: string|null, 1: string}>
     */
    private function errorCodes(array $data): array
    {
        return array_map(fn(array $error) => [$error['id'], $error['code']], $data['errors'] ?? []);
    }
}
