<?php

namespace lameco\rankroute\tests\integration;

use Craft;
use craft\base\Field;
use craft\elements\Category;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Dropdown;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use nystudio107\seomatic\fields\SeoSettings;
use RuntimeException;

/**
 * Content fixture for the optimizer export/import tests: a section + entry type with
 * PlainText, Dropdown and Matrix (one block type, one PlainText field) custom fields, a
 * second site, an entry that exists on both sites, and a category group with one category.
 * Seeded inside the transaction each test opens (see IntegrationTestCase), which keeps the
 * fixture self-contained without a class-level teardown.
 */
abstract class ContentFixtureTestCase extends IntegrationTestCase
{
    protected int $primarySiteId;
    protected int $nlSiteId;
    protected int $entryId;
    protected string $entrySlug;
    /** Multi-segment on purpose: the resolver has to survive a URI that is not just a slug. */
    protected string $entryUri;
    protected int $categoryId;
    protected string $categorySlug;

    protected const PLAIN_TEXT_HANDLE = 'bodyText';
    protected const DROPDOWN_HANDLE = 'topic';
    protected const MATRIX_HANDLE = 'blocks';
    protected const BLOCK_PLAIN_TEXT_HANDLE = 'blockText';
    protected const SEOMATIC_HANDLE = 'seoSettings';

    protected function seedContent(): void
    {
        $primarySite = Craft::$app->getSites()->getPrimarySite();
        $this->primarySiteId = $primarySite->id;
        $this->nlSiteId = $this->createNlSite($primarySite);

        [$plainText, $dropdown, $matrix, $seoSettings] = $this->createFields();

        $entryType = new EntryType(['name' => 'Article', 'handle' => 'article']);
        $layout = new FieldLayout(['type' => Entry::class]);
        $layout->setTabs([
            [
                'name' => 'Content',
                'elements' => [
                    new EntryTitleField(),
                    new CustomField($plainText),
                    new CustomField($dropdown),
                    new CustomField($matrix),
                    new CustomField($seoSettings),
                ],
            ],
        ]);
        $entryType->setFieldLayout($layout);

        if (!Craft::$app->getEntries()->saveEntryType($entryType)) {
            throw new RuntimeException('Could not save the "article" entry type: ' . implode(', ', $entryType->getErrorSummary(true)));
        }

        $section = new Section([
            'name' => 'Blog',
            'handle' => 'blog',
            'type' => Section::TYPE_CHANNEL,
        ]);
        $section->setEntryTypes([$entryType]);
        $section->setSiteSettings([
            new Section_SiteSettings([
                'siteId' => $this->primarySiteId,
                'hasUrls' => true,
                'uriFormat' => 'blog/{slug}',
            ]),
            new Section_SiteSettings([
                'siteId' => $this->nlSiteId,
                'hasUrls' => true,
                'uriFormat' => 'blog/{slug}',
            ]),
        ]);

        if (!Craft::$app->getEntries()->saveSection($section)) {
            throw new RuntimeException('Could not save the "blog" section: ' . implode(', ', $section->getErrorSummary(true)));
        }

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->siteId = $this->primarySiteId;
        $entry->title = 'Original title';
        $entry->slug = 'original-entry';
        $entry->setFieldValues([
            self::PLAIN_TEXT_HANDLE => 'Original body text',
            self::DROPDOWN_HANDLE => 'news',
        ]);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new RuntimeException('Could not save the fixture entry: ' . implode(', ', $entry->getErrorSummary(true)));
        }

        $this->entryId = $entry->id;
        $this->entrySlug = $entry->slug;
        $this->entryUri = 'blog/' . $entry->slug;

        $categoryGroupLayout = new FieldLayout(['type' => Category::class]);
        $categoryGroupLayout->setTabs([
            [
                'name' => 'Content',
                'elements' => [
                    new CustomField($seoSettings),
                ],
            ],
        ]);

        $categoryGroup = new CategoryGroup([
            'name' => 'Topics',
            'handle' => 'topics',
        ]);
        $categoryGroup->setFieldLayout($categoryGroupLayout);
        $categoryGroup->setSiteSettings([
            new CategoryGroup_SiteSettings([
                'siteId' => $this->primarySiteId,
                'hasUrls' => true,
                'uriFormat' => 'topics/{slug}',
            ]),
            new CategoryGroup_SiteSettings([
                'siteId' => $this->nlSiteId,
                'hasUrls' => true,
                'uriFormat' => 'topics/{slug}',
            ]),
        ]);

        if (!Craft::$app->getCategories()->saveGroup($categoryGroup)) {
            throw new RuntimeException('Could not save the "topics" category group: ' . implode(', ', $categoryGroup->getErrorSummary(true)));
        }

        $category = new Category();
        $category->groupId = $categoryGroup->id;
        $category->siteId = $this->primarySiteId;
        $category->title = 'Original category';
        $category->slug = 'original-category';

        if (!Craft::$app->getElements()->saveElement($category)) {
            throw new RuntimeException('Could not save the fixture category: ' . implode(', ', $category->getErrorSummary(true)));
        }

        $this->categoryId = $category->id;
        $this->categorySlug = $category->slug;
    }

    private function createNlSite(Site $primarySite): int
    {
        $nlSite = new Site([
            'groupId' => $primarySite->groupId,
            'name' => 'RankRoute NL',
            'handle' => 'nl',
            'language' => 'nl-NL',
            'hasUrls' => true,
            'baseUrl' => 'https://rankroute.test/nl/',
            'primary' => false,
        ]);

        if (!Craft::$app->getSites()->saveSite($nlSite)) {
            throw new RuntimeException('Could not save the "nl" site: ' . implode(', ', $nlSite->getErrorSummary(true)));
        }

        return $nlSite->id;
    }

    /**
     * @return array{0: PlainText, 1: Dropdown, 2: Matrix, 3: SeoSettings}
     */
    private function createFields(): array
    {
        $fieldsService = Craft::$app->getFields();

        $plainText = new PlainText(['name' => 'Body Text', 'handle' => self::PLAIN_TEXT_HANDLE]);
        if (!$fieldsService->saveField($plainText)) {
            throw new RuntimeException('Could not save the PlainText field: ' . implode(', ', $plainText->getErrorSummary(true)));
        }

        $dropdown = new Dropdown([
            'name' => 'Topic',
            'handle' => self::DROPDOWN_HANDLE,
            'options' => [
                ['label' => 'News', 'value' => 'news'],
                ['label' => 'Guides', 'value' => 'guides'],
            ],
        ]);
        if (!$fieldsService->saveField($dropdown)) {
            throw new RuntimeException('Could not save the Dropdown field: ' . implode(', ', $dropdown->getErrorSummary(true)));
        }

        $blockPlainText = new PlainText(['name' => 'Block Text', 'handle' => self::BLOCK_PLAIN_TEXT_HANDLE]);
        if (!$fieldsService->saveField($blockPlainText)) {
            throw new RuntimeException('Could not save the block PlainText field: ' . implode(', ', $blockPlainText->getErrorSummary(true)));
        }

        $blockEntryType = new EntryType(['name' => 'Text Block', 'handle' => 'textBlock']);
        $blockLayout = new FieldLayout(['type' => Entry::class]);
        $blockLayout->setTabs([
            [
                'name' => 'Content',
                'elements' => [
                    new CustomField($blockPlainText),
                ],
            ],
        ]);
        $blockEntryType->setFieldLayout($blockLayout);

        if (!Craft::$app->getEntries()->saveEntryType($blockEntryType)) {
            throw new RuntimeException('Could not save the "textBlock" block entry type: ' . implode(', ', $blockEntryType->getErrorSummary(true)));
        }

        $matrix = new Matrix(['name' => 'Blocks', 'handle' => self::MATRIX_HANDLE]);
        $matrix->setEntryTypes([$blockEntryType]);

        if (!$fieldsService->saveField($matrix)) {
            throw new RuntimeException('Could not save the Matrix field: ' . implode(', ', $matrix->getErrorSummary(true)));
        }

        // Per-site, like the fixture's other multi-site content, so the /nl/ resolution
        // case (D7) writes to the nl site's element without touching the primary one.
        $seoSettings = new SeoSettings([
            'name' => 'SEO Settings',
            'handle' => self::SEOMATIC_HANDLE,
            'translationMethod' => Field::TRANSLATION_METHOD_SITE,
        ]);
        if (!$fieldsService->saveField($seoSettings)) {
            throw new RuntimeException('Could not save the SeoSettings field: ' . implode(', ', $seoSettings->getErrorSummary(true)));
        }

        return [$plainText, $dropdown, $matrix, $seoSettings];
    }
}
