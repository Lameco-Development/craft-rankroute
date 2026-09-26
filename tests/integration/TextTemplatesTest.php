<?php

namespace lameco\rankroute\tests\integration;

use Craft;
use craft\db\Query;
use craft\db\Table as DbTable;
use craft\elements\Entry;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\Db;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use DateTime;
use RuntimeException;

/**
 * `text/templates`: the kinds of page (section × entry type) a new page can be copied
 * from, with their live entry count and the most recent live entries with a URL.
 *
 * On top of the text flow fixture (structure `pages`, three live `page` entries, nested
 * `card` and `textBlock` entries):
 * - channel `news`, URLs in the primary site only:
 *   - `article`: six live entries (one without a URI), a disabled one, a pending one, an
 *     expired one, a trashed one, and a draft of the newest
 *   - `pressRelease`: only a disabled entry
 *   - `card` (the fixture's nested card type): one live entry
 * - channel `archive` without URLs, single `about` with a URL: both `article`, both live
 */
final class TextTemplatesTest extends TextFlowFixtureTestCase
{
    /** @var array<string, int> */
    private array $articleIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTextFlowContent();
        $this->seedTemplates();
        $this->setApiKey(self::API_KEY);
    }

    public function testListsEveryCopyableKindWithItsLiveEntriesAndSamples(): void
    {
        $response = $this->textAction('templates');

        self::assertSame(200, $response->getStatusCode(), json_encode($response->data));
        self::assertSame(['siteId', 'templates'], array_keys($response->data));
        self::assertSame($this->primarySiteId, $response->data['siteId']);
        self::assertSame(
            [['news', 'article', 6], ['pages', 'page', 3], ['news', 'card', 1]],
            $this->kinds($response->data['templates']),
        );

        [$articles, $pages, $cards] = $response->data['templates'];

        self::assertSame([
            'section' => ['handle' => 'news', 'name' => 'News', 'type' => 'channel'],
            'entryType' => ['handle' => 'article', 'name' => 'Article'],
            'liveEntries' => 6,
            'samples' => [
                $this->sample($this->articleIds['May']),
                $this->sample($this->articleIds['April']),
                $this->sample($this->articleIds['March']),
            ],
        ], $articles);
        self::assertStringContainsString('news/may', $articles['samples'][0]['url']);

        self::assertSame(['handle' => 'pages', 'name' => 'Pages', 'type' => 'structure'], $pages['section']);
        self::assertSame(['handle' => 'page', 'name' => 'Page'], $pages['entryType']);
        self::assertSame(
            [$this->sample($this->pageId), $this->sample($this->relatedEntryId), $this->sample($this->parentPageId)],
            $pages['samples'],
        );
        self::assertStringContainsString('solutions/applications', $pages['samples'][0]['url']);

        self::assertSame(['handle' => 'card', 'name' => 'Card'], $cards['entryType']);
        self::assertSame(['Standalone card'], array_column($cards['samples'], 'title'));
    }

    public function testOnlyLiveEntriesCountAndOnlyLiveEntriesWithAUrlAreSamples(): void
    {
        $articles = $this->template('news', 'article');

        // Disabled, pending, expired, trashed and the draft are not counted; the live entry
        // without a URI is counted but is never a sample, although it is the newest.
        self::assertSame(6, $articles['liveEntries']);
        self::assertSame(['May', 'April', 'March'], array_column($articles['samples'], 'title'));
        self::assertNotContains($this->articleIds['No URI'], array_column($articles['samples'], 'elementId'));
    }

    public function testAKindWithoutALiveEntryIsNotListed(): void
    {
        self::assertNull($this->template('news', 'pressRelease'));
    }

    public function testNestedEntriesDoNotCount(): void
    {
        // The fixture page has two live nested cards of the same entry type.
        self::assertSame(2, (int)Entry::find()->type('card')->fieldId($this->fields['items']->id)->siteId($this->primarySiteId)->count());
        self::assertSame(1, $this->template('news', 'card')['liveEntries']);

        // Nested-only entry types (textBlock, button, ...) are never listed.
        foreach ($this->textAction('templates')->data['templates'] as $template) {
            self::assertContains($template['section']['handle'], ['news', 'pages']);
        }
    }

    public function testSinglesAndSectionsWithoutUrlsAreNotListed(): void
    {
        self::assertNotNull(Entry::find()->section('about')->one());
        self::assertNotNull(Entry::find()->section('archive')->one());

        $sections = array_column(array_column($this->textAction('templates')->data['templates'], 'section'), 'handle');

        self::assertNotContains('about', $sections);
        self::assertNotContains('archive', $sections);
    }

    public function testTheSiteDecidesUrlsTitlesAndWhichSectionsCount(): void
    {
        $response = $this->textAction('templates', ['siteId' => (string)$this->nlSiteId]);

        self::assertSame(200, $response->getStatusCode(), json_encode($response->data));
        self::assertSame($this->nlSiteId, $response->data['siteId']);
        // News has no URLs in the nl site.
        self::assertSame([['pages', 'page', 3]], $this->kinds($response->data['templates']));

        $samples = $response->data['templates'][0]['samples'];
        self::assertSame(['Toepassingen', 'Contact', 'Solutions'], array_column($samples, 'title'));
        self::assertSame($this->page($this->nlSiteId)->getUrl(), $samples[0]['url']);
    }

    public function testWithoutASiteIdThePrimarySiteIsUsed(): void
    {
        $default = $this->textAction('templates');

        foreach (['', (string)$this->primarySiteId] as $siteId) {
            self::assertSame($default->data, $this->textAction('templates', ['siteId' => $siteId])->data, $siteId);
        }
    }

    public function testAnUnknownOrMalformedSiteIdIsABadRequest(): void
    {
        foreach (['99999', 'abc', '1.5', '-1', ' 1', ['1']] as $siteId) {
            $response = $this->textAction('templates', ['siteId' => $siteId]);
            $label = json_encode($siteId);

            self::assertSame(400, $response->getStatusCode(), $label);
            self::assertSame(['error'], array_keys($response->data), $label);
        }

        self::assertSame(['error' => 'Unknown siteId "99999".'], $this->textAction('templates', ['siteId' => '99999'])->data);
    }

    public function testNothingIsWritten(): void
    {
        $counts = fn() => [
            (new Query())->from(DbTable::ELEMENTS)->count(),
            (new Query())->from(DbTable::ELEMENTS_SITES)->count(),
            (new Query())->from(DbTable::DRAFTS)->count(),
            (new Query())->from(DbTable::REVISIONS)->count(),
            (new Query())->from(DbTable::ELEMENTS)->max('dateUpdated'),
        ];
        $before = $counts();

        self::assertSame(200, $this->textAction('templates')->getStatusCode());
        self::assertSame(200, $this->textAction('templates', ['siteId' => (string)$this->nlSiteId])->getStatusCode());
        self::assertSame($before, $counts());
    }

    // Fixture --------------------------------------------------------------------------

    private function seedTemplates(): void
    {
        $article = $this->simpleEntryType('Article', 'article');
        $pressRelease = $this->simpleEntryType('Press release', 'pressRelease');
        $card = Craft::$app->getEntries()->getEntryTypeByHandle('card') ?? throw new RuntimeException('No card entry type.');

        $news = $this->section('News', 'news', Section::TYPE_CHANNEL, [$article, $pressRelease, $card], [
            $this->primarySiteId => 'news/{slug}',
            $this->nlSiteId => null,
        ]);

        foreach (['January' => 50, 'February' => 40, 'March' => 30, 'April' => 20, 'May' => 10, 'No URI' => 5] as $title => $daysAgo) {
            $this->articleIds[$title] = $this->saveEntry($news, $article, $title, ['postDate' => new DateTime("-{$daysAgo} days")])->id;
        }

        // A live entry whose URI is missing: counted, but it cannot be a sample.
        Db::update(DbTable::ELEMENTS_SITES, ['uri' => null], ['elementId' => $this->articleIds['No URI']]);

        $this->saveEntry($news, $article, 'Disabled', ['postDate' => new DateTime('-1 day'), 'enabled' => false]);
        $this->saveEntry($news, $article, 'Pending', ['postDate' => new DateTime('+30 days')]);
        $this->saveEntry($news, $article, 'Expired', ['postDate' => new DateTime('-3 days'), 'expiryDate' => new DateTime('-2 days')]);
        $trashed = $this->saveEntry($news, $article, 'Trashed', ['postDate' => new DateTime('-2 days')]);
        Craft::$app->getElements()->deleteElement($trashed);

        $newest = Entry::find()->id($this->articleIds['May'])->siteId($this->primarySiteId)->one() ?? throw new RuntimeException('No May article.');
        Craft::$app->getDrafts()->createDraft($newest);

        $this->saveEntry($news, $pressRelease, 'Draft release', ['postDate' => new DateTime('-1 day'), 'enabled' => false]);
        $this->saveEntry($news, $card, 'Standalone card', ['postDate' => new DateTime('-1 day')]);

        $archive = $this->section('Archive', 'archive', Section::TYPE_CHANNEL, [$article], [$this->primarySiteId => null]);
        $this->saveEntry($archive, $article, 'Archived', ['postDate' => new DateTime('-1 day')]);

        $this->section('About', 'about', Section::TYPE_SINGLE, [$article], [$this->primarySiteId => 'about']);
    }

    private function simpleEntryType(string $name, string $handle): EntryType
    {
        $entryType = new EntryType(['name' => $name, 'handle' => $handle, 'hasTitleField' => true]);
        $layout = new FieldLayout(['type' => Entry::class]);
        $layout->setTabs([['name' => 'Content', 'elements' => [new EntryTitleField()]]]);
        $entryType->setFieldLayout($layout);

        if (!Craft::$app->getEntries()->saveEntryType($entryType)) {
            throw new RuntimeException("Could not save entry type {$handle}: " . implode(', ', $entryType->getErrorSummary(true)));
        }

        return $entryType;
    }

    /**
     * @param EntryType[] $entryTypes
     * @param array<int, string|null> $uriFormats per site id; null = no URLs in that site
     */
    private function section(string $name, string $handle, string $type, array $entryTypes, array $uriFormats): Section
    {
        $section = new Section(['name' => $name, 'handle' => $handle, 'type' => $type]);
        $section->setEntryTypes($entryTypes);
        $section->setSiteSettings(array_map(
            fn(int $siteId) => new Section_SiteSettings([
                'siteId' => $siteId,
                'hasUrls' => $uriFormats[$siteId] !== null,
                'uriFormat' => $uriFormats[$siteId],
            ]),
            array_keys($uriFormats),
        ));

        if (!Craft::$app->getEntries()->saveSection($section)) {
            throw new RuntimeException("Could not save the \"{$handle}\" section: " . implode(', ', $section->getErrorSummary(true)));
        }

        return $section;
    }

    /**
     * @param array{postDate?: DateTime, expiryDate?: DateTime, enabled?: bool} $attributes
     */
    private function saveEntry(Section $section, EntryType $type, string $title, array $attributes = []): Entry
    {
        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $type->id;
        $entry->siteId = $this->primarySiteId;
        $entry->title = $title;
        $entry->slug = strtolower(str_replace(' ', '-', $title));
        $entry->postDate = $attributes['postDate'] ?? null;
        $entry->expiryDate = $attributes['expiryDate'] ?? null;
        $entry->enabled = $attributes['enabled'] ?? true;

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new RuntimeException("Could not save entry {$title}: " . implode(', ', $entry->getErrorSummary(true)));
        }

        return $entry;
    }

    // Helpers --------------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $templates
     * @return list<array{0: string, 1: string, 2: int}> [section handle, entry type handle, live entries]
     */
    private function kinds(array $templates): array
    {
        return array_map(fn(array $template) => [$template['section']['handle'], $template['entryType']['handle'], $template['liveEntries']], $templates);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function template(string $section, string $entryType): ?array
    {
        foreach ($this->textAction('templates')->data['templates'] as $template) {
            if ($template['section']['handle'] === $section && $template['entryType']['handle'] === $entryType) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @return array{elementId: int, title: string, url: string|null}
     */
    private function sample(int $elementId): array
    {
        $entry = Entry::find()->id($elementId)->siteId($this->primarySiteId)->one() ?? throw new RuntimeException("Entry {$elementId} is not live.");

        return ['elementId' => $elementId, 'title' => (string)$entry->title, 'url' => $entry->getUrl()];
    }
}
