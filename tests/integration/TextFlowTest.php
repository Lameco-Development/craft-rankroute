<?php

namespace lameco\rankroute\tests\integration;

use Craft;
use craft\base\ElementInterface;
use craft\db\Table as DbTable;
use craft\elements\Entry;
use craft\events\ModelEvent;
use lameco\rankroute\services\text\HtmlSkeleton;
use yii\base\Event;
use yii\web\Response;

/**
 * The text flow end to end through the booted app: export shape and exclusions,
 * fingerprint, import writing only targeted strings onto a draft, every rejection path,
 * the structure check and the verify endpoint, multi-site.
 */
final class TextFlowTest extends TextFlowFixtureTestCase
{
    private const EXPECTED_IDS = [
        'title',
        'seo.seoTitle',
        'headerTitle',
        'intro',
        'introSecondary',
        'pageBuilder[0].heading',
        'pageBuilder[0].content',
        'pageBuilder[2].title',
        'pageBuilder[2].items[0].title',
        'pageBuilder[2].items[0].cardText',
        'pageBuilder[2].items[0].contentBuilder[0].richBody',
        'pageBuilder[2].items[0].contentBuilder[1].actionsIntro',
        'pageBuilder[2].items[1].title',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTextFlowContent();
        $this->setApiKey(self::API_KEY);
    }

    // Export ---------------------------------------------------------------------------

    public function testExportReturnsTheElementTheFingerprintAndTheTextItems(): void
    {
        $response = $this->textAction('export', ['id' => $this->pageId]);

        self::assertSame(200, $response->getStatusCode());
        $data = $response->data;

        self::assertSame(['element', 'fingerprint', 'items'], array_keys($data));
        self::assertSame(['id', 'siteId', 'type', 'url', 'cpEditUrl'], array_keys($data['element']));
        self::assertSame($this->pageId, $data['element']['id']);
        self::assertSame($this->primarySiteId, $data['element']['siteId']);
        self::assertSame(Entry::class, $data['element']['type']);
        self::assertStringContainsString('solutions/applications', (string)$data['element']['url']);
        self::assertNotEmpty($data['element']['cpEditUrl']);
        self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $data['fingerprint']);

        self::assertSame(self::EXPECTED_IDS, array_column($data['items'], 'id'));

        $items = array_column($data['items'], null, 'id');
        self::assertSame(['id' => 'title', 'type' => 'plain', 'value' => 'Applications', 'maxLength' => 255], $items['title']);
        self::assertSame(['id' => 'seo.seoTitle', 'type' => 'plain', 'value' => 'Applications | SEO title', 'maxLength' => null], $items['seo.seoTitle']);
        self::assertSame(['id' => 'intro', 'type' => 'plain', 'value' => 'A short introduction', 'maxLength' => 160], $items['intro']);
        self::assertSame('The second instance of the intro field', $items['introSecondary']['value']);
        self::assertSame('html', $items['headerTitle']['type']);
        self::assertStringContainsString('<a ', $items['headerTitle']['value']);
        self::assertSame(
            ['id' => 'pageBuilder[0].content', 'type' => 'html', 'value' => '<p>Read <a href="/about">about us</a> now.</p><ul><li>One</li><li>Two</li></ul>', 'maxLength' => null],
            $items['pageBuilder[0].content'],
        );
        self::assertSame('First card', $items['pageBuilder[2].items[0].title']['value']);
        self::assertSame(255, $items['pageBuilder[2].items[0].title']['maxLength']);
        self::assertSame('<p>Deep <strong>rich</strong> text</p>', $items['pageBuilder[2].items[0].contentBuilder[0].richBody']['value']);
    }

    public function testExportLeavesOutEverythingThatIsNotRewritableText(): void
    {
        $data = $this->exportDocument();
        $ids = array_column($data['items'], 'id');
        $values = array_column($data['items'], 'value');

        foreach ($ids as $id) {
            // Links, assets, relations, options, tables, buttons: never items.
            self::assertDoesNotMatchRegularExpression('/cta|image|related|topic|specs|buttons|linkText|linkType|label/', $id);
            // Empty, URL, number, Twig and handle-excluded text fields.
            self::assertDoesNotMatchRegularExpression('/emptyText|website|phone|externalUrl|twigText/', $id);
            // The disabled entry and the entry with only empty text.
            self::assertStringStartsNotWith('pageBuilder[1]', $id);
            self::assertStringStartsNotWith('pageBuilder[3]', $id);
            // Entry types without a title field have no title item.
            self::assertNotSame('pageBuilder[0].title', $id);
            self::assertNotSame('pageBuilder[2].items[0].contentBuilder[0].title', $id);
        }

        // SEOmatic description holds Twig: not extractable.
        self::assertNotContains('seo.seoDescription', $ids);
        // Card 2's cardText is empty: its title is an item, the empty text is not.
        self::assertContains('pageBuilder[2].items[1].title', $ids);
        self::assertNotContains('pageBuilder[2].items[1].cardText', $ids);

        foreach ($values as $value) {
            self::assertNotSame('', trim($value));
            self::assertStringNotContainsString('{{', $value);
            self::assertStringNotContainsString('Call now', $value);
            self::assertStringNotContainsString('Hidden', $value);
        }
    }

    public function testExportByUrlAndByPathResolveTheSameElement(): void
    {
        $byId = $this->exportDocument();
        $byUrl = $this->exportDocument(['url' => 'https://rankroute.test/' . $this->pageUri]);
        $byPath = $this->exportDocument(['url' => $this->pageUri]);

        self::assertSame($byId, $byUrl);
        self::assertSame($byId, $byPath);
    }

    public function testExcludeFieldsConfigIsHonoured(): void
    {
        $this->plugin()->textExtractor->excludeFields = ['intro*'];

        $ids = array_column($this->exportDocument()['items'], 'id');

        self::assertContains('externalUrl', $ids);
        self::assertNotContains('intro', $ids);
        self::assertNotContains('introSecondary', $ids);
    }

    public function testExportWithoutUrlOrIdIsABadRequest(): void
    {
        $response = $this->textAction('export');

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $response->data);
    }

    public function testExportOfAnUnknownElementIsNotFound(): void
    {
        $byId = $this->textAction('export', ['id' => 999999]);
        $byUrl = $this->textAction('export', ['url' => 'https://rankroute.test/no/such/page']);

        self::assertSame(404, $byId->getStatusCode());
        self::assertArrayHasKey('error', $byId->data);
        self::assertSame(404, $byUrl->getStatusCode());
    }

    public function testEveryEndpointNeedsTheApiKey(): void
    {
        foreach (['export' => ['id' => $this->pageId], 'import' => [], 'verify' => ['draftId' => 1]] as $action => $query) {
            $missing = $this->textAction($action, $query, '{}', apiKey: null);
            self::assertSame(401, $missing->getStatusCode(), $action);
            self::assertArrayHasKey('error', $missing->data);

            $wrong = $this->textAction($action, $query, '{}', apiKey: 'wrong-key');
            self::assertSame(401, $wrong->getStatusCode(), $action);
        }

        self::assertSame(0, $this->draftCount());
    }

    /**
     * Craft treats an action that returns no response as "no action" and routes the path on
     * as a page: `/actions/rankroute/text/export` with a rejected key used to come back as
     * Craft's own 404, and `?action=` as the homepage with a 401. The rejection must be the
     * action's own response, so this runs the action without textAction()'s fallback to
     * Craft's response object.
     */
    public function testARejectedKeyIsTheActionsOwnResponse(): void
    {
        CraftHarness::useWebRequest();
        $this->plugin();

        foreach (['export', 'import', 'create', 'verify'] as $action) {
            $result = Craft::$app->runAction('rankroute/text/' . $action);

            self::assertInstanceOf(Response::class, $result, $action);
            self::assertSame(401, $result->getStatusCode(), $action);
            self::assertSame(['error' => 'Authentication required'], $result->data, $action);
        }
    }

    // Fingerprint ----------------------------------------------------------------------

    public function testFingerprintIsStableAcrossExports(): void
    {
        self::assertSame($this->exportDocument()['fingerprint'], $this->exportDocument()['fingerprint']);
    }

    public function testFingerprintChangesWhenTextChanges(): void
    {
        $before = $this->exportDocument()['fingerprint'];

        $page = $this->page();
        $page->setFieldValue('intro', 'Another introduction');
        self::assertTrue(Craft::$app->getElements()->saveElement($page));

        self::assertNotSame($before, $this->exportDocument()['fingerprint']);
    }

    public function testFingerprintChangesWhenOnlyStructureChanges(): void
    {
        $before = $this->exportDocument();

        $page = $this->page();
        $page->setFieldValue('cta', ['type' => 'url', 'value' => 'https://example.com/other', 'label' => 'Call to action']);
        self::assertTrue(Craft::$app->getElements()->saveElement($page));

        $after = $this->exportDocument();
        self::assertSame($before['items'], $after['items']);
        self::assertNotSame($before['fingerprint'], $after['fingerprint']);
    }

    public function testFingerprintChangesWhenANestedButtonLinkChanges(): void
    {
        $before = $this->exportDocument()['fingerprint'];

        $button = $this->nested($this->page(), [['pageBuilder', 2], ['items', 0], ['contentBuilder', 1], ['buttons', 0]]);
        $button->setFieldValue('linkText', ['type' => 'url', 'value' => 'https://example.com/elsewhere', 'label' => 'Contact']);
        self::assertTrue(Craft::$app->getElements()->saveElement($button));

        self::assertNotSame($before, $this->exportDocument()['fingerprint']);
    }

    // Import: writes ---------------------------------------------------------------------

    public function testImportWritesOnlyTheTargetedStringsOntoADraft(): void
    {
        $export = $this->exportDocument();
        $items = array_column($export['items'], 'value', 'id');
        $canonicalBefore = $this->flatten($this->page());

        $changes = [
            'title' => 'Applications for industry',
            'seo.seoTitle' => 'Industrial applications | RankRoute',
            'headerTitle' => str_replace(['Welcome to', ' applications'], ['Discover', ' industrial applications'], $items['headerTitle']),
            'introSecondary' => 'A rewritten second instance',
            'pageBuilder[0].content' => '<p>Learn <a href="/about">who we are</a> today.</p><ul><li>First</li><li>Second</li></ul>',
            'pageBuilder[2].items[0].title' => 'The very first card',
            'pageBuilder[2].items[0].contentBuilder[0].richBody' => '<p>Deeper <strong>richer</strong> text</p>',
            'pageBuilder[2].items[0].contentBuilder[1].actionsIntro' => 'Choose an action',
        ];

        $response = $this->importDocument($this->payloadFor($export, $changes));

        self::assertSame(200, $response->getStatusCode(), json_encode($response->data));
        $data = $response->data;
        self::assertSame(
            ['success', 'elementId', 'siteId', 'draftId', 'draftElementId', 'cpEditUrl', 'changedItems', 'structureCheck', 'replayed'],
            array_keys($data),
        );
        self::assertTrue($data['success']);
        self::assertSame($this->pageId, $data['elementId']);
        self::assertSame($this->primarySiteId, $data['siteId']);
        self::assertIsInt($data['draftId']);
        self::assertIsInt($data['draftElementId']);
        self::assertNotEmpty($data['cpEditUrl']);
        self::assertSame(array_values(array_intersect(self::EXPECTED_IDS, array_keys($changes))), $data['changedItems']);
        self::assertSame(['passed' => true, 'differences' => []], $data['structureCheck']);
        self::assertSame(1, $this->draftCount());

        $draft = $this->draft($data['draftId']);
        self::assertSame($data['draftElementId'], $draft->id);

        // Every text item of the draft: changed ones carry the new text, the rest are as they were.
        $draftItems = array_column(array_map(fn($item) => $item->toArray(), $this->plugin()->textExtractor->items($draft)), 'value', 'id');
        self::assertSame(self::EXPECTED_IDS, array_keys($draftItems));

        foreach ($draftItems as $id => $value) {
            if (!isset($changes[$id])) {
                self::assertSame($items[$id], $value, $id);
            } elseif (str_contains($changes[$id], '<')) {
                // Relaxed: Craft rewrites the reference tag fallback URL when it saves.
                self::assertTrue(HtmlSkeleton::equals($changes[$id], $value, relaxedReferenceTags: true), $id . json_encode([$changes[$id], $value]));
                self::assertSame(HtmlSkeleton::textContent($changes[$id]), HtmlSkeleton::textContent($value), $id);
            } else {
                self::assertSame($changes[$id], $value, $id);
            }
        }

        // Every other stored value of the whole tree, nested entry types, order and
        // statuses included, is identical to the canonical.
        $draftFlat = $this->flatten($draft);
        $canonicalWithoutChanged = array_diff_key($canonicalBefore, $changes);
        self::assertSame($canonicalWithoutChanged, array_diff_key($draftFlat, $changes));
        self::assertSame(array_keys($canonicalBefore), array_keys($draftFlat));

        // Untouched nested entries are the canonical entries themselves; touched ones are
        // derivatives of them.
        $page = $this->page();
        foreach ([[['pageBuilder', 1]], [['pageBuilder', 3]], [['pageBuilder', 2], ['items', 1]], [['pageBuilder', 2], ['items', 0], ['contentBuilder', 1], ['buttons', 0]]] as $path) {
            self::assertSame($this->nested($page, $path)->id, $this->nested($draft, $path)->id, json_encode($path));
        }
        foreach ([[['pageBuilder', 0]], [['pageBuilder', 2]], [['pageBuilder', 2], ['items', 0]], [['pageBuilder', 2], ['items', 0], ['contentBuilder', 0]]] as $path) {
            $canonicalEntry = $this->nested($page, $path);
            $draftEntry = $this->nested($draft, $path);
            self::assertNotSame($canonicalEntry->id, $draftEntry->id, json_encode($path));
            self::assertSame($canonicalEntry->id, $draftEntry->getCanonicalId(), json_encode($path));
        }

        // The canonical is untouched: same stored values, same export, same fingerprint.
        self::assertSame($canonicalBefore, $this->flatten($this->page()));
        self::assertSame($export, $this->exportDocument());

        // The other site of the draft is untouched too.
        self::assertSame($this->flatten($this->page($this->nlSiteId)), $this->flatten($this->draft($data['draftId'], $this->nlSiteId)));
    }

    public function testImportOfATopLevelTextOnlyLeavesNestedEntriesAlone(): void
    {
        $export = $this->exportDocument();

        $response = $this->importDocument($this->payloadFor($export, ['intro' => 'A new introduction']));

        self::assertSame(200, $response->getStatusCode(), json_encode($response->data));
        self::assertSame(['intro'], $response->data['changedItems']);

        $draft = $this->draft($response->data['draftId']);
        self::assertSame('A new introduction', $draft->getFieldValue('intro'));
        self::assertSame('The second instance of the intro field', $draft->getFieldValue('introSecondary'));
        self::assertSame(
            $this->nested($this->page(), [['pageBuilder', 2]])->id,
            $this->nested($draft, [['pageBuilder', 2]])->id,
        );
    }

    public function testImportOfTheUnchangedExportCreatesNoDraft(): void
    {
        $export = $this->exportDocument();

        $response = $this->importDocument($this->payloadFor($export));

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->data['success']);
        self::assertSame([], $response->data['changedItems']);
        self::assertNull($response->data['draftId']);
        self::assertSame(['passed' => true, 'differences' => []], $response->data['structureCheck']);
        self::assertSame(0, $this->draftCount());
    }

    public function testImportByUrlWorks(): void
    {
        $export = $this->exportDocument();
        $payload = $this->payloadFor($export, ['intro' => 'Via url']);
        unset($payload['elementId'], $payload['siteId']);
        $payload['url'] = 'https://rankroute.test/' . $this->pageUri;

        $response = $this->importDocument($payload);

        self::assertSame(200, $response->getStatusCode(), json_encode($response->data));
        self::assertSame(['intro'], $response->data['changedItems']);
    }

    /**
     * CKEditor purifies HTML on save: entities and whitespace can come back normalised,
     * which must not count as a structure change.
     */
    public function testHtmlThatThePurifierNormalisesStillPassesTheStructureCheck(): void
    {
        $export = $this->exportDocument();

        $response = $this->importDocument($this->payloadFor($export, [
            'pageBuilder[0].content' => "<p>Fish &amp; chips&nbsp;<a href='/about'>about  us</a> &#8211; now.</p><ul><li>One &gt; two</li><li>Two</li></ul>",
        ]));

        self::assertSame(200, $response->getStatusCode(), json_encode($response->data));
        self::assertTrue($response->data['structureCheck']['passed']);
    }

    public function testImportOnTheNlSiteWritesOnlyThatSite(): void
    {
        $export = $this->exportDocument(['url' => 'nl/' . $this->pageUri]);
        self::assertSame($this->nlSiteId, $export['element']['siteId']);

        $items = array_column($export['items'], 'value', 'id');
        self::assertSame('Toepassingen', $items['title']);
        self::assertSame('Een korte introductie', $items['intro']);

        $primaryBefore = $this->flatten($this->page());

        $response = $this->importDocument($this->payloadFor($export, [
            'title' => 'Industriële toepassingen',
            'intro' => 'Een nieuwe introductie',
            'pageBuilder[0].heading' => 'Nieuwe kop',
        ]));

        self::assertSame(200, $response->getStatusCode(), json_encode($response->data));
        self::assertSame($this->nlSiteId, $response->data['siteId']);
        self::assertTrue($response->data['structureCheck']['passed']);

        $nlDraft = $this->draft($response->data['draftId'], $this->nlSiteId);
        self::assertSame('Industriële toepassingen', $nlDraft->title);
        self::assertSame('Een nieuwe introductie', $nlDraft->getFieldValue('intro'));
        self::assertSame('Nieuwe kop', $this->nested($nlDraft, [['pageBuilder', 0]])->getFieldValue('heading'));

        self::assertSame($primaryBefore, $this->flatten($this->draft($response->data['draftId'], $this->primarySiteId)));
        self::assertSame($primaryBefore, $this->flatten($this->page()));
        self::assertSame('Toepassingen', $this->page($this->nlSiteId)->title);
    }

    // Import: rejections -------------------------------------------------------------------

    public function testStaleFingerprintIsAConflict(): void
    {
        $export = $this->exportDocument();

        $page = $this->page();
        $page->setFieldValue('topic', 'guides');
        self::assertTrue(Craft::$app->getElements()->saveElement($page));

        $response = $this->importDocument($this->payloadFor($export, ['intro' => 'Changed']));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(false, $response->data['success']);
        self::assertSame('fingerprint_mismatch', $response->data['errors'][0]['code']);
        self::assertSame(0, $this->draftCount());
    }

    /**
     * @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string, 2: string|null}>
     */
    public static function rejectionProvider(): array
    {
        return [
            'unknown id' => [
                fn(array $payload) => [...$payload, 'items' => [...$payload['items'], ['id' => 'pageBuilder[3].heading', 'value' => 'Added']]],
                'unknown_id',
                'pageBuilder[3].heading',
            ],
            'missing id' => [
                fn(array $payload) => [...$payload, 'items' => array_slice($payload['items'], 0, -1)],
                'missing_id',
                'pageBuilder[2].items[1].title',
            ],
            'duplicate id' => [
                fn(array $payload) => [...$payload, 'items' => [...$payload['items'], ['id' => 'title', 'value' => 'Twice']]],
                'duplicate_id',
                'title',
            ],
            'empty value' => [
                fn(array $payload) => self::withValue($payload, 'pageBuilder[0].heading', '   '),
                'empty_value',
                'pageBuilder[0].heading',
            ],
            'too long' => [
                fn(array $payload) => self::withValue($payload, 'intro', str_repeat('x', 161)),
                'too_long',
                'intro',
            ],
            'html in plain' => [
                fn(array $payload) => self::withValue($payload, 'title', 'Our <strong>applications</strong>'),
                'html_in_plain',
                'title',
            ],
            'changed href' => [
                fn(array $payload) => self::withValue($payload, 'pageBuilder[0].content', '<p>Read <a href="/evil">about us</a> now.</p><ul><li>One</li><li>Two</li></ul>'),
                'html_structure_changed',
                'pageBuilder[0].content',
            ],
            'removed list item' => [
                fn(array $payload) => self::withValue($payload, 'pageBuilder[0].content', '<p>Read <a href="/about">about us</a> now.</p><ul><li>One</li></ul>'),
                'html_structure_changed',
                'pageBuilder[0].content',
            ],
            'twig' => [
                fn(array $payload) => self::withValue($payload, 'title', '{{ craft.app.config.general.securityKey }}'),
                'twig_in_value',
                'title',
            ],
            'env var in seo' => [
                fn(array $payload) => self::withValue($payload, 'seo.seoTitle', '${DB_PASSWORD}'),
                'forbidden_syntax',
                'seo.seoTitle',
            ],
            'alias in seo' => [
                fn(array $payload) => self::withValue($payload, 'seo.seoTitle', '@root'),
                'forbidden_syntax',
                'seo.seoTitle',
            ],
            'added reference tag' => [
                fn(array $payload) => self::withValue($payload, 'pageBuilder[0].content', '<p>Read <a href="/about">about us</a> {user:1:email}.</p><ul><li>One</li><li>Two</li></ul>'),
                'reference_tag_changed',
                'pageBuilder[0].content',
            ],
            'changed reference tag fallback' => [
                fn(array $payload) => [...$payload, 'items' => array_map(
                    fn(array $item) => $item['id'] === 'headerTitle'
                        ? [...$item, 'value' => preg_replace('/\|\|[^}]*\}/', '||https://evil.example}', $item['value'])]
                        : $item,
                    $payload['items'],
                )],
                'reference_tag_changed',
                'headerTitle',
            ],
            'comment smuggling' => [
                fn(array $payload) => self::withValue($payload, 'pageBuilder[0].content', '<p>Read <a href="/about">about us</a> now.</p><!--><script>alert(1)</script><!-- --><ul><li>One</li><li>Two</li></ul>'),
                'html_structure_changed',
                'pageBuilder[0].content',
            ],
            'unclosed tag in plain' => [
                fn(array $payload) => self::withValue($payload, 'intro', 'x <img src=x onerror=alert(1)//'),
                'html_in_plain',
                'intro',
            ],
            'invalid idempotency key' => [
                fn(array $payload) => [...$payload, 'idempotencyKey' => 'not valid!'],
                'invalid_idempotency_key',
                null,
            ],
        ];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectionProvider')]
    public function testInvalidSubmissionsAreRejectedAndWriteNothing(callable $mutate, string $code, ?string $id): void
    {
        $export = $this->exportDocument();
        // A valid change alongside the invalid one proves all-or-nothing.
        $payload = $mutate($this->payloadFor($export, ['intro' => 'A valid change']));

        $response = $this->importDocument($payload);

        self::assertSame(422, $response->getStatusCode(), json_encode($response->data));
        self::assertSame(['success', 'errors'], array_keys($response->data));
        self::assertFalse($response->data['success']);
        self::assertContains(['id' => $id, 'code' => $code], array_map(
            fn(array $error) => ['id' => $error['id'], 'code' => $error['code']],
            $response->data['errors'],
        ));
        self::assertSame(0, $this->draftCount());
        self::assertSame($export, $this->exportDocument());
    }

    public function testImportOfAnUnknownElementIsNotFound(): void
    {
        $response = $this->importDocument(['elementId' => 999999, 'siteId' => $this->primarySiteId, 'fingerprint' => 'sha256:x', 'items' => []]);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $response->data);
    }

    public function testMalformedImportBodiesAreBadRequests(): void
    {
        foreach (['', 'not json', '[]', '{"elementId": 1, "fingerprint": "x"}', '{"elementId": 1, "items": []}'] as $body) {
            $response = $this->textAction('import', rawBody: $body);
            self::assertSame(400, $response->getStatusCode(), $body);
            self::assertArrayHasKey('error', $response->data, $body);
        }
    }

    public function testExportByIdOfANestedEntryIsNotFound(): void
    {
        $nested = $this->nested($this->page(), [['pageBuilder', 0]]);

        self::assertSame(404, $this->textAction('export', ['id' => $nested->id])->getStatusCode());
        self::assertSame(404, $this->textAction('export', ['id' => $this->assetId])->getStatusCode());
    }

    public function testUnexpectedFailuresAnswerAGenericMessageWithAReference(): void
    {
        $export = $this->exportDocument();

        Event::on(Entry::class, Entry::EVENT_BEFORE_SAVE, function(ModelEvent $event) {
            if ($event->sender->getIsDraft()) {
                throw new \RuntimeException('secret detail /var/www/.env');
            }
        });

        $response = $this->importDocument($this->payloadFor($export, ['intro' => 'A new introduction']));

        self::assertSame(500, $response->getStatusCode());
        self::assertMatchesRegularExpression('/^Internal error \(ref [0-9a-f]{8}\)\.$/', $response->data['error']);
        self::assertSame(0, $this->draftCount());
    }

    // Idempotency ---------------------------------------------------------------------------

    public function testRetryWithTheSameIdempotencyKeyReplaysTheFirstDraft(): void
    {
        $export = $this->exportDocument();
        $payload = [...$this->payloadFor($export, ['intro' => 'A new introduction', 'pageBuilder[0].heading' => 'New heading']), 'idempotencyKey' => 'run-page-42'];

        $first = $this->importDocument($payload);
        self::assertSame(200, $first->getStatusCode(), json_encode($first->data));
        self::assertFalse($first->data['replayed']);

        // The retry is answered from the stored draft, even with a now stale payload.
        $retry = $this->importDocument([...$payload, 'fingerprint' => 'sha256:stale', 'items' => []]);

        self::assertSame(200, $retry->getStatusCode(), json_encode($retry->data));
        self::assertTrue($retry->data['replayed']);
        self::assertSame($first->data['draftId'], $retry->data['draftId']);
        self::assertSame($first->data['draftElementId'], $retry->data['draftElementId']);
        self::assertSame(['intro', 'pageBuilder[0].heading'], $retry->data['changedItems']);
        self::assertSame(['passed' => true, 'differences' => []], $retry->data['structureCheck']);
        self::assertSame(1, $this->draftCount());
    }

    public function testADifferentIdempotencyKeyCreatesASecondDraft(): void
    {
        $export = $this->exportDocument();
        $payload = $this->payloadFor($export, ['intro' => 'A new introduction']);

        $first = $this->importDocument([...$payload, 'idempotencyKey' => 'run-page-1']);
        // Creating a draft does not change the canonical, so the fingerprint still matches.
        $second = $this->importDocument([...$payload, 'idempotencyKey' => 'run-page-2']);

        self::assertSame(200, $second->getStatusCode(), json_encode($second->data));
        self::assertFalse($second->data['replayed']);
        self::assertNotSame($first->data['draftId'], $second->data['draftId']);
        self::assertSame(2, $this->draftCount());
    }

    public function testTheIdempotencyKeyIsScopedToTheSite(): void
    {
        $primary = $this->importDocument([...$this->payloadFor($this->exportDocument(), ['intro' => 'New']), 'idempotencyKey' => 'run-page-7']);
        $nl = $this->importDocument([...$this->payloadFor($this->exportDocument(['url' => 'nl/' . $this->pageUri]), ['intro' => 'Nieuw']), 'idempotencyKey' => 'run-page-7']);

        self::assertFalse($nl->data['replayed']);
        self::assertNotSame($primary->data['draftId'], $nl->data['draftId']);
        self::assertSame(2, $this->draftCount());
    }

    // Shared nested entries -------------------------------------------------------------------

    public function testTextInANestedEntrySharedFromAnotherElementIsNeverAnItem(): void
    {
        $this->shareIntroBlockWithTheRelatedPage();

        $ids = array_column($this->exportDocument(['id' => $this->relatedEntryId])['items'], 'id');

        self::assertSame(['title'], $ids);
    }

    public function testTheImportRefusesToWriteIntoASharedNestedEntry(): void
    {
        $this->shareIntroBlockWithTheRelatedPage();
        $this->plugin()->textExtractor->skipSharedNestedEntries = false;

        $export = $this->exportDocument(['id' => $this->relatedEntryId]);
        self::assertContains('pageBuilder[0].heading', array_column($export['items'], 'id'));

        $response = $this->importDocument($this->payloadFor($export, ['pageBuilder[0].heading' => 'Hijacked heading']));

        self::assertSame(422, $response->getStatusCode(), json_encode($response->data));
        self::assertSame([['id' => 'pageBuilder[0].heading', 'code' => 'shared_nested_entry']], array_map(
            fn(array $error) => ['id' => $error['id'], 'code' => $error['code']],
            $response->data['errors'],
        ));
        self::assertSame('Intro heading', $this->nested($this->page(), [['pageBuilder', 0]])->getFieldValue('heading'));
    }

    // Structure check & verify -------------------------------------------------------------------

    public function testVerifyPassesForAnImportedDraft(): void
    {
        $draftId = $this->importChange('pageBuilder[2].items[0].contentBuilder[1].actionsIntro', 'Choose an action');

        $response = $this->textAction('verify', ['draftId' => $draftId]);

        self::assertSame(200, $response->getStatusCode(), json_encode($response->data));
        self::assertSame([
            'success' => true,
            'elementId' => $this->pageId,
            'siteId' => $this->primarySiteId,
            'draftId' => $draftId,
            'draftElementId' => $this->draft($draftId)->id,
            'structureCheck' => ['passed' => true, 'differences' => []],
        ], $response->data);
    }

    public function testVerifyFailsWhenATopLevelLinkOrAssetChangesOnTheDraft(): void
    {
        $draftId = $this->importChange('intro', 'A new introduction');

        $draft = $this->draft($draftId);
        $draft->setFieldValue('cta', ['type' => 'url', 'value' => 'https://example.com/hijacked', 'label' => 'Call to action']);
        $draft->setFieldValue('image', [$this->otherAssetId]);
        self::assertTrue(Craft::$app->getElements()->saveElement($draft));

        $response = $this->textAction('verify', ['draftId' => $draftId]);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->data['structureCheck']['passed']);
        $paths = array_column($response->data['structureCheck']['differences'], 'path');
        self::assertContains('fields.cta.value.value', $paths);
        self::assertContains('fields.image.value[0]', $paths);
    }

    public function testVerifyFailsWhenANestedButtonLinkChangesOnTheDraft(): void
    {
        $draftId = $this->importChange('pageBuilder[2].items[0].contentBuilder[1].actionsIntro', 'Choose an action');
        $draft = $this->draft($draftId);

        // Change the first button's Link on the draft only, through the same delta
        // mechanism the import uses.
        $button = $this->nested($draft, [['pageBuilder', 2], ['items', 0], ['contentBuilder', 1], ['buttons', 0]]);
        $draft->setFieldValue('pageBuilder', $this->delta($draft, [['pageBuilder', 2], ['items', 0], ['contentBuilder', 1], ['buttons', 0]], [
            'linkText' => ['type' => 'url', 'value' => 'https://example.com/hijacked', 'label' => 'Contact'],
        ]));
        self::assertTrue(Craft::$app->getElements()->saveElement($draft));

        $response = $this->textAction('verify', ['draftId' => $draftId]);

        self::assertFalse($response->data['structureCheck']['passed']);
        self::assertContains(
            'fields.pageBuilder.entries[2].fields.items.entries[0].fields.contentBuilder.entries[1].fields.buttons.entries[0].fields.linkText.value.value',
            array_column($response->data['structureCheck']['differences'], 'path'),
        );

        // The canonical button is untouched.
        $canonicalButton = Entry::find()->id($button->id)->siteId($this->primarySiteId)->status(null)->one();
        self::assertSame('https://example.com/contact', $canonicalButton->getFieldValue('linkText')->value);
    }

    public function testVerifyOfAnUnknownDraftIsNotFound(): void
    {
        self::assertSame(404, $this->textAction('verify', ['draftId' => 999999])->getStatusCode());
        self::assertSame(400, $this->textAction('verify')->getStatusCode());
    }

    public function testImportDiscardsTheDraftWhenTheStructureCheckFails(): void
    {
        $export = $this->exportDocument();

        // Something else (another plugin, a listener) changes a non-text value while the
        // draft is saved.
        Event::on(Entry::class, Entry::EVENT_BEFORE_SAVE, function(ModelEvent $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            if ($entry->getIsDraft() && $entry->getCanonicalId() === $this->pageId) {
                $entry->setFieldValue('topic', 'guides');
            }
        });

        $response = $this->importDocument($this->payloadFor($export, ['intro' => 'A new introduction']));

        self::assertSame(500, $response->getStatusCode(), json_encode($response->data));
        self::assertFalse($response->data['success']);
        self::assertSame('structure_check_failed', $response->data['errors'][0]['code']);
        self::assertFalse($response->data['structureCheck']['passed']);
        self::assertSame([['path' => 'fields.topic.value', 'before' => 'news', 'after' => 'guides']], $response->data['structureCheck']['differences']);
        self::assertSame(0, $this->draftCount());
    }

    // Helpers -----------------------------------------------------------------------------

    /**
     * Makes the page's first pageBuilder entry also a nested entry of the related page
     * (an extra ownership row; its primary owner stays the page).
     */
    private function shareIntroBlockWithTheRelatedPage(): void
    {
        $block = $this->nested($this->page(), [['pageBuilder', 0]]);

        Craft::$app->getDb()->createCommand()->insert(DbTable::ELEMENTS_OWNERS, [
            'elementId' => $block->id,
            'ownerId' => $this->relatedEntryId,
            'sortOrder' => 1,
        ])->execute();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function withValue(array $payload, string $id, string $value): array
    {
        foreach ($payload['items'] as $index => $item) {
            if ($item['id'] === $id) {
                $payload['items'][$index]['value'] = $value;
            }
        }

        return $payload;
    }

    private function importChange(string $id, string $value): int
    {
        $response = $this->importDocument($this->payloadFor($this->exportDocument(), [$id => $value]));
        self::assertSame(200, $response->getStatusCode(), json_encode($response->data));

        return $response->data['draftId'];
    }

    /**
     * Craft's delta Matrix value for one nested entry deep in the tree, keeping every
     * sibling at every level.
     *
     * @param list<array{0: string, 1: int}> $path
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function delta(ElementInterface $owner, array $path, array $fields): array
    {
        [$handle, $index] = array_shift($path);
        $entries = Entry::find()->fieldId($this->fields[$handle]->id)->ownerId($owner->id)->siteId($owner->siteId)->status(null)->all();
        $entry = $entries[$index];

        $entryFields = $path === []
            ? $fields
            : [$path[0][0] => $this->delta($entry, $path, $fields)];

        return [
            'entries' => [$entry->id => ['fields' => $entryFields]],
            'sortOrder' => array_map(fn(Entry $e) => $e->id, $entries),
        ];
    }
}
