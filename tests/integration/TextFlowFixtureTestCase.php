<?php

namespace lameco\rankroute\tests\integration;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\FieldInterface;
use craft\ckeditor\Field as CkeditorField;
use craft\db\Query;
use craft\db\Table as DbTable;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Assets;
use craft\fields\Dropdown;
use craft\fields\Entries;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\fs\Local;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use craft\models\Volume;
use nystudio107\seomatic\fields\SeoSettings;
use RuntimeException;
use yii\web\Response;

/**
 * A page shaped like the real Laméco sites, for the text flow: a Structure section with
 * two sites, a CKEditor header with a link, a PlainText field used twice in the layout
 * (multi-instance), empty/URL/number/Twig/excluded text, Dropdown, Assets, Entries, Table,
 * Link, SEOmatic, and a `pageBuilder` Matrix nested four levels deep
 * (pageBuilder → items → contentBuilder → buttons) with entry types without a title
 * field, a disabled entry, and a `button` entry type with a Dropdown `linkType` and a Link
 * `linkText`.
 *
 * pageBuilder of the page, by index:
 * - 0 textBlock: heading "Intro heading", content with `<a href="/about">`
 * - 1 textBlock, disabled: "Hidden heading" (never extracted)
 * - 2 cardsBlock "Cards title", items:
 *   - 0 card "First card", cardText, cardImage (the other asset), contentBuilder:
 *     - 0 richText: `<p>Deep <strong>rich</strong> text</p>`
 *     - 1 actionsBlock: "Pick an action", buttons: 0 url button, 1 entry button
 *   - 1 card "Second card", cardText empty
 * - 3 textBlock with empty heading and content
 */
abstract class TextFlowFixtureTestCase extends IntegrationTestCase
{
    protected const API_KEY = 'text-flow-key';

    protected int $primarySiteId;
    protected int $nlSiteId;
    protected int $pageId;
    protected string $pageUri;
    protected int $parentPageId;
    protected int $relatedEntryId;
    protected int $assetId;
    protected int $otherAssetId;

    /** @var array<string, FieldInterface> */
    protected array $fields = [];

    /**
     * Extra sites the page is propagated to, by handle: `[handle => [name, language, baseUrl]]`.
     * Set before {@see seedTextFlowContent()}; their ids end up in {@see $extraSiteIds}.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    protected array $extraSites = [];

    /** @var array<string, int> */
    protected array $extraSiteIds = [];

    protected function seedTextFlowContent(): void
    {
        $primarySite = Craft::$app->getSites()->getPrimarySite();
        $this->primarySiteId = $primarySite->id;
        $this->nlSiteId = $this->createSite($primarySite, 'nl', 'RankRoute NL', 'nl-NL', 'https://rankroute.test/nl/');

        foreach ($this->extraSites as $handle => [$name, $language, $baseUrl]) {
            $this->extraSiteIds[$handle] = $this->createSite($primarySite, $handle, $name, $language, $baseUrl);
        }

        [$this->assetId, $this->otherAssetId] = $this->createAssets();
        $this->createFields();

        $pageType = $this->entryType('Page', 'page', [
            new EntryTitleField(),
            'headerTitle',
            'intro',
            new CustomField($this->fields['intro'], ['handle' => 'introSecondary']),
            'emptyText',
            'website',
            'phone',
            'externalUrl',
            'twigText',
            'topic',
            'image',
            'related',
            'specs',
            'cta',
            'pageBuilder',
            'seo',
        ]);

        $section = new Section([
            'name' => 'Pages',
            'handle' => 'pages',
            'type' => Section::TYPE_STRUCTURE,
        ]);
        $section->setEntryTypes([$pageType]);
        $section->setSiteSettings(array_map(
            fn(int $siteId) => new Section_SiteSettings(['siteId' => $siteId, 'hasUrls' => true, 'uriFormat' => '{parent.uri}/{slug}']),
            [$this->primarySiteId, $this->nlSiteId, ...array_values($this->extraSiteIds)],
        ));

        if (!Craft::$app->getEntries()->saveSection($section)) {
            throw new RuntimeException('Could not save the "pages" section: ' . implode(', ', $section->getErrorSummary(true)));
        }

        $parent = $this->saveEntry($section, $pageType, ['title' => 'Solutions', 'slug' => 'solutions']);
        $this->parentPageId = $parent->id;
        $related = $this->saveEntry($section, $pageType, ['title' => 'Contact', 'slug' => 'contact']);
        $this->relatedEntryId = $related->id;

        $page = $this->saveEntry($section, $pageType, [
            'title' => 'Applications',
            'slug' => 'applications',
            'parentId' => $parent->id,
            'fields' => [
                'headerTitle' => '<p>Welcome to <a href="https://rankroute.test/contact" target="_blank">our</a> applications</p>',
                'intro' => 'A short introduction',
                'introSecondary' => 'The second instance of the intro field',
                'emptyText' => '',
                'website' => 'https://www.example.com/page',
                'phone' => '+31 (0)6 1234 5678',
                'externalUrl' => 'Text in a field whose handle is excluded',
                'twigText' => 'Hello {{ entry.title }}',
                'topic' => 'news',
                'image' => [$this->assetId],
                'related' => [$related->id],
                'specs' => [
                    ['col1' => 'Weight', 'col2' => '10 kg'],
                    ['col1' => 'Colour', 'col2' => 'Blue'],
                ],
                'cta' => ['type' => 'url', 'value' => 'https://example.com/cta', 'label' => 'Call to action'],
                'pageBuilder' => [
                    'new1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => [
                        'heading' => 'Intro heading',
                        'content' => '<p>Read <a href="/about">about us</a> now.</p><ul><li>One</li><li>Two</li></ul>',
                    ]],
                    'new2' => ['type' => 'textBlock', 'enabled' => false, 'fields' => [
                        'heading' => 'Hidden heading',
                        'content' => '<p>Hidden content</p>',
                    ]],
                    'new3' => ['type' => 'cardsBlock', 'enabled' => true, 'title' => 'Cards title', 'fields' => [
                        'items' => [
                            'new1' => ['type' => 'card', 'enabled' => true, 'title' => 'First card', 'fields' => [
                                'cardText' => 'First card text',
                                'cardImage' => [$this->otherAssetId],
                                'contentBuilder' => [
                                    'new1' => ['type' => 'richText', 'enabled' => true, 'fields' => [
                                        'richBody' => '<p>Deep <strong>rich</strong> text</p>',
                                    ]],
                                    'new2' => ['type' => 'actionsBlock', 'enabled' => true, 'fields' => [
                                        'actionsIntro' => 'Pick an action',
                                        'buttons' => [
                                            'new1' => ['type' => 'button', 'enabled' => true, 'fields' => [
                                                'linkType' => 'url',
                                                'linkText' => ['type' => 'url', 'value' => 'https://example.com/contact', 'label' => 'Contact'],
                                                'label' => 'Call now',
                                            ]],
                                            'new2' => ['type' => 'button', 'enabled' => true, 'fields' => [
                                                'linkType' => 'entry',
                                                'linkText' => ['type' => 'entry', 'value' => $related->id, 'label' => 'Contact page'],
                                                'label' => 'Visit us',
                                            ]],
                                        ],
                                    ]],
                                ],
                            ]],
                            'new2' => ['type' => 'card', 'enabled' => true, 'title' => 'Second card', 'fields' => [
                                'cardText' => '',
                            ]],
                        ],
                    ]],
                    'new4' => ['type' => 'textBlock', 'enabled' => true, 'fields' => [
                        'heading' => '',
                        'content' => '',
                    ]],
                ],
                'seo' => [
                    'metaGlobalVars' => [
                        'seoTitle' => 'Applications | SEO title',
                        'seoDescription' => '{{ entry.intro }}',
                    ],
                ],
            ],
        ]);

        $this->pageId = $page->id;
        $this->pageUri = 'solutions/applications';

        // The nl site gets its own title and texts, so a multi-site import can prove it
        // only writes the site it was asked to.
        $nlPage = $this->page($this->nlSiteId);
        $nlPage->title = 'Toepassingen';
        $nlPage->setFieldValue('intro', 'Een korte introductie');

        if (!Craft::$app->getElements()->saveElement($nlPage)) {
            throw new RuntimeException('Could not save the nl page: ' . implode(', ', $nlPage->getErrorSummary(true)));
        }
    }

    /**
     * Dispatches a text flow action. Unlike {@see runAction()} it accepts an action that
     * answered from `beforeAction()` (the 401 body): Yii then returns nothing and the web
     * app would send the response component as is.
     *
     * @param array<string, mixed> $queryParams
     */
    protected function textAction(string $action, array $queryParams = [], ?string $rawBody = null, ?string $apiKey = self::API_KEY): Response
    {
        CraftHarness::useWebRequest();
        $this->plugin();

        $request = Craft::$app->getRequest();

        if ($apiKey !== null) {
            $request->getHeaders()->set('Authorization', 'Bearer ' . $apiKey);
        }

        $request->setQueryParams($queryParams);

        if ($rawBody !== null) {
            $request->setRawBody($rawBody);
        }

        $result = Craft::$app->runAction('rankroute/text/' . $action);

        return $result instanceof Response ? $result : Craft::$app->getResponse();
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    protected function exportDocument(array $query = []): array
    {
        $response = $this->textAction('export', $query ?: ['id' => $this->pageId]);

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Export failed: ' . json_encode($response->data));
        }

        return $response->data;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function importDocument(array $payload): Response
    {
        return $this->textAction('import', rawBody: (string)json_encode($payload));
    }

    /**
     * The import payload for an export, with some values replaced.
     *
     * @param array<string, mixed> $export
     * @param array<string, string> $changes
     * @return array<string, mixed>
     */
    protected function payloadFor(array $export, array $changes = []): array
    {
        return [
            'elementId' => $export['element']['id'],
            'siteId' => $export['element']['siteId'],
            'fingerprint' => $export['fingerprint'],
            'items' => array_map(
                fn(array $item) => ['id' => $item['id'], 'value' => $changes[$item['id']] ?? $item['value']],
                $export['items'],
            ),
        ];
    }

    protected function draftCount(): int
    {
        return (int)(new Query())->from(DbTable::DRAFTS)->where(['canonicalId' => $this->pageId])->count();
    }

    protected function draft(int $draftId, ?int $siteId = null): Entry
    {
        $draft = Entry::find()->draftId($draftId)->siteId($siteId ?? $this->primarySiteId)->status(null)->one();

        if (!$draft instanceof Entry) {
            throw new RuntimeException("Draft {$draftId} not found.");
        }

        return $draft;
    }

    protected function page(?int $siteId = null): Entry
    {
        $page = Entry::find()->id($this->pageId)->siteId($siteId ?? $this->primarySiteId)->status(null)->one();

        if (!$page instanceof Entry) {
            throw new RuntimeException('The fixture page is gone.');
        }

        return $page;
    }

    /**
     * The nested entry at a path of Matrix handles and indexes, on any owner (canonical or draft).
     *
     * @param list<array{0: string, 1: int}> $path
     */
    protected function nested(Entry $owner, array $path): Entry
    {
        $current = $owner;

        foreach ($path as [$handle, $index]) {
            $entries = Entry::find()
                ->fieldId($this->fields[$handle]->id)
                ->ownerId($current->id)
                ->siteId($current->siteId)
                ->status(null)
                ->all();

            if (!isset($entries[$index])) {
                throw new RuntimeException("No nested entry {$handle}[{$index}].");
            }

            $current = $entries[$index];
        }

        return $current;
    }

    /**
     * Every stored value of an element tree as `address => value`: titles, custom field
     * values as serialised for the database, and per nested entry its canonical id, type
     * and status, addressed like text items.
     *
     * @return array<string, mixed>
     */
    protected function flatten(ElementInterface $element, string $prefix = ''): array
    {
        $flat = [];
        $flat[$prefix . 'title'] = $element->title;

        foreach ($element->getFieldLayout()->getCustomFields() as $field) {
            if ($field instanceof Matrix) {
                $entries = Entry::find()->fieldId($field->id)->ownerId($element->id)->siteId($element->siteId)->status(null)->all();
                $flat[$prefix . $field->handle . '#count'] = count($entries);

                foreach ($entries as $index => $entry) {
                    $entryPrefix = "{$prefix}{$field->handle}[{$index}].";
                    $flat[$entryPrefix . '#canonicalId'] = $entry->getCanonicalId();
                    $flat[$entryPrefix . '#type'] = $entry->getType()->handle;
                    $flat[$entryPrefix . '#enabled'] = $entry->enabled;
                    $flat += $this->flatten($entry, $entryPrefix);
                }

                continue;
            }

            if ($field->handle === 'seo') {
                $bundle = $element->getFieldValue('seo');
                $flat[$prefix . 'seo.seoTitle'] = $bundle->metaGlobalVars->seoTitle;
                $flat[$prefix . 'seo.seoDescription'] = $bundle->metaGlobalVars->seoDescription;
                $flat[$prefix . 'seo.metaBundleSettings'] = json_encode($bundle->metaBundleSettings);
                continue;
            }

            $flat[$prefix . $field->handle] = json_encode($field->serializeValueForDb($element->getFieldValue($field->handle), $element));
        }

        return $flat;
    }

    private function createSite(Site $primarySite, string $handle, string $name, string $language, string $baseUrl): int
    {
        $site = new Site([
            'groupId' => $primarySite->groupId,
            'name' => $name,
            'handle' => $handle,
            'language' => $language,
            'hasUrls' => true,
            'baseUrl' => $baseUrl,
            'primary' => false,
        ]);

        if (!Craft::$app->getSites()->saveSite($site)) {
            throw new RuntimeException("Could not save the \"{$handle}\" site: " . implode(', ', $site->getErrorSummary(true)));
        }

        return $site->id;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function createAssets(): array
    {
        $fs = new Local([
            'name' => 'Test files',
            'handle' => 'testFiles',
            'path' => dirname(__DIR__) . '/_craft/storage/test-assets',
            'hasUrls' => true,
            'url' => 'https://rankroute.test/assets/',
        ]);

        if (!Craft::$app->getFs()->saveFilesystem($fs)) {
            throw new RuntimeException('Could not save the filesystem: ' . implode(', ', $fs->getErrorSummary(true)));
        }

        $volume = new Volume(['name' => 'Images', 'handle' => 'images']);
        $volume->setFsHandle('testFiles');

        if (!Craft::$app->getVolumes()->saveVolume($volume)) {
            throw new RuntimeException('Could not save the volume: ' . implode(', ', $volume->getErrorSummary(true)));
        }

        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
        $ids = [];

        foreach (['hero.txt', 'other.txt'] as $filename) {
            $tempFile = tempnam(sys_get_temp_dir(), 'rankroute');
            file_put_contents($tempFile, "fixture {$filename}");

            $asset = new Asset();
            $asset->tempFilePath = $tempFile;
            $asset->setFilename($filename);
            $asset->newFolderId = $folder->id;
            $asset->volumeId = $volume->id;
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);

            if (!Craft::$app->getElements()->saveElement($asset)) {
                throw new RuntimeException('Could not save the fixture asset: ' . implode(', ', $asset->getErrorSummary(true)));
            }

            $ids[] = $asset->id;
        }

        return [$ids[0], $ids[1]];
    }

    private function createFields(): void
    {
        $translatable = ['translationMethod' => Field::TRANSLATION_METHOD_SITE];

        $this->saveField(new CkeditorField(['name' => 'Header title', 'handle' => 'headerTitle', ...$translatable]));
        $this->saveField(new PlainText(['name' => 'Intro', 'handle' => 'intro', 'charLimit' => 160, ...$translatable]));
        $this->saveField(new PlainText(['name' => 'Empty text', 'handle' => 'emptyText', ...$translatable]));
        $this->saveField(new PlainText(['name' => 'Website', 'handle' => 'website', ...$translatable]));
        $this->saveField(new PlainText(['name' => 'Phone', 'handle' => 'phone', ...$translatable]));
        $this->saveField(new PlainText(['name' => 'External URL', 'handle' => 'externalUrl', ...$translatable]));
        $this->saveField(new PlainText(['name' => 'Twig text', 'handle' => 'twigText', ...$translatable]));
        $this->saveField(new Dropdown([
            'name' => 'Topic',
            'handle' => 'topic',
            'options' => [
                ['label' => 'News', 'value' => 'news'],
                ['label' => 'Guides', 'value' => 'guides'],
            ],
        ]));
        $this->saveField(new Assets(['name' => 'Image', 'handle' => 'image', 'sources' => '*']));
        $this->saveField(new Entries(['name' => 'Related', 'handle' => 'related', 'sources' => '*']));
        $this->saveField(new Table([
            'name' => 'Specs',
            'handle' => 'specs',
            'columns' => [
                'col1' => ['heading' => 'Property', 'handle' => 'property', 'type' => 'singleline'],
                'col2' => ['heading' => 'Value', 'handle' => 'value', 'type' => 'singleline'],
            ],
        ]));
        $this->saveField(new Link([
            'name' => 'CTA',
            'handle' => 'cta',
            'types' => ['url', 'entry'],
            'showLabelField' => true,
        ]));
        $this->saveField(new SeoSettings(['name' => 'SEO', 'handle' => 'seo', ...$translatable]));

        // Nested levels, innermost first.
        $this->saveField(new PlainText(['name' => 'Heading', 'handle' => 'heading', ...$translatable]));
        $this->saveField(new CkeditorField(['name' => 'Content', 'handle' => 'content', ...$translatable]));
        $this->saveField(new PlainText(['name' => 'Card text', 'handle' => 'cardText', ...$translatable]));
        $this->saveField(new CkeditorField(['name' => 'Rich body', 'handle' => 'richBody', ...$translatable]));
        $this->saveField(new PlainText(['name' => 'Actions intro', 'handle' => 'actionsIntro', ...$translatable]));
        $this->saveField(new PlainText(['name' => 'Label', 'handle' => 'label', ...$translatable]));
        $this->saveField(new Dropdown([
            'name' => 'Link type',
            'handle' => 'linkType',
            'options' => [
                ['label' => 'URL', 'value' => 'url'],
                ['label' => 'Entry', 'value' => 'entry'],
            ],
        ]));
        $this->saveField(new Link([
            'name' => 'Link text',
            'handle' => 'linkText',
            'types' => ['url', 'entry'],
            'showLabelField' => true,
        ]));

        $button = $this->entryType('Button', 'button', ['linkType', 'linkText', 'label'], hasTitleField: false);
        $this->saveMatrix('Buttons', 'buttons', [$button]);

        $richText = $this->entryType('Rich text', 'richText', ['richBody'], hasTitleField: false);
        $actionsBlock = $this->entryType('Actions block', 'actionsBlock', ['actionsIntro', 'buttons'], hasTitleField: false);
        $this->saveMatrix('Content builder', 'contentBuilder', [$richText, $actionsBlock]);

        $this->saveField(new Assets(['name' => 'Card image', 'handle' => 'cardImage', 'sources' => '*']));
        $card = $this->entryType('Card', 'card', [new EntryTitleField(), 'cardText', 'cardImage', 'contentBuilder']);
        $this->saveMatrix('Items', 'items', [$card]);

        $textBlock = $this->entryType('Text block', 'textBlock', ['heading', 'content'], hasTitleField: false);
        $cardsBlock = $this->entryType('Cards block', 'cardsBlock', [new EntryTitleField(), 'items']);
        $this->saveMatrix('Page builder', 'pageBuilder', [$textBlock, $cardsBlock]);
    }

    private function saveField(FieldInterface $field): void
    {
        if (!Craft::$app->getFields()->saveField($field)) {
            throw new RuntimeException("Could not save field {$field->handle}: " . implode(', ', $field->getErrorSummary(true)));
        }

        $this->fields[$field->handle] = $field;
    }

    /**
     * @param EntryType[] $entryTypes
     */
    private function saveMatrix(string $name, string $handle, array $entryTypes): void
    {
        $matrix = new Matrix(['name' => $name, 'handle' => $handle]);
        $matrix->setEntryTypes($entryTypes);
        $this->saveField($matrix);
    }

    /**
     * @param list<string|CustomField|EntryTitleField> $elements field handles or layout elements
     */
    private function entryType(string $name, string $handle, array $elements, bool $hasTitleField = true): EntryType
    {
        $entryType = new EntryType(['name' => $name, 'handle' => $handle, 'hasTitleField' => $hasTitleField]);
        $layout = new FieldLayout(['type' => Entry::class]);
        $layout->setTabs([[
            'name' => 'Content',
            'elements' => array_map(
                fn($element) => is_string($element) ? new CustomField($this->fields[$element]) : $element,
                $elements,
            ),
        ]]);
        $entryType->setFieldLayout($layout);

        if (!Craft::$app->getEntries()->saveEntryType($entryType)) {
            throw new RuntimeException("Could not save entry type {$handle}: " . implode(', ', $entryType->getErrorSummary(true)));
        }

        return $entryType;
    }

    /**
     * @param array{title: string, slug: string, parentId?: int, fields?: array<string, mixed>} $attributes
     */
    private function saveEntry(Section $section, EntryType $type, array $attributes): Entry
    {
        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $type->id;
        $entry->siteId = $this->primarySiteId;
        $entry->title = $attributes['title'];
        $entry->slug = $attributes['slug'];

        if (isset($attributes['parentId'])) {
            $entry->setParentId($attributes['parentId']);
        }

        $entry->setFieldValues($attributes['fields'] ?? []);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new RuntimeException("Could not save entry {$attributes['slug']}: " . implode(', ', $entry->getErrorSummary(true)));
        }

        return $entry;
    }
}
