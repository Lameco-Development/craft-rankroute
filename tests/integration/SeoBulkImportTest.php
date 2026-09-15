<?php

namespace lameco\rankroute\tests\integration;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;

/**
 * The bulk meta write ported from craft-seo-import 1.0.4 (issue #3): resolution through
 * the shared {@see \lameco\rankroute\services\ElementResolver} (D7 — multi-site, any
 * element), `No url provided` reported instead of skipped silently (D12), and an explicit
 * 4xx when SEOmatic is absent (constraint 4) instead of skipping every item.
 */
final class SeoBulkImportTest extends ContentFixtureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedContent();
        $this->setApiKey('correct-key');
    }

    public function testTwoValidItemsAreUpdatedWithTheOverrideFlagsSet(): void
    {
        $categoryUrl = 'https://rankroute.test/topics/' . $this->categorySlug;
        $entryUrl = 'https://rankroute.test/' . $this->entryUri;

        $response = $this->runAction(
            'rankroute/seo/import',
            'Bearer correct-key',
            rawBody: json_encode([
                ['url' => $entryUrl, 'meta_title' => 'New entry title', 'meta_description' => 'New entry description'],
                ['url' => $categoryUrl, 'meta_title' => 'New category title'],
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'success' => true,
            'updated' => 2,
            'total' => 2,
            'skipped' => [],
        ], $response->data);

        $entry = Craft::$app->getElements()->getElementById($this->entryId, Entry::class, $this->primarySiteId);
        $entrySeo = $entry->getFieldValue(self::SEOMATIC_HANDLE);
        self::assertSame('New entry title', $entrySeo->metaGlobalVars->seoTitle);
        self::assertArrayHasKey('seoTitle', $entrySeo->metaGlobalVars->overrides);
        self::assertSame('New entry description', $entrySeo->metaGlobalVars->seoDescription);
        self::assertArrayHasKey('seoDescription', $entrySeo->metaGlobalVars->overrides);

        $category = Craft::$app->getElements()->getElementById($this->categoryId, Category::class, $this->primarySiteId);
        $categorySeo = $category->getFieldValue(self::SEOMATIC_HANDLE);
        self::assertSame('New category title', $categorySeo->metaGlobalVars->seoTitle);
        self::assertArrayHasKey('seoTitle', $categorySeo->metaGlobalVars->overrides);
    }

    public function testItemWithoutUrlIsSkippedWithItsReasonAndCountedInTotal(): void
    {
        $response = $this->runAction(
            'rankroute/seo/import',
            'Bearer correct-key',
            rawBody: json_encode([
                ['meta_title' => 'No url here'],
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $response->data['updated']);
        self::assertSame(1, $response->data['total']);
        self::assertSame([['url' => null, 'reason' => 'No url provided']], $response->data['skipped']);
    }

    public function testItemWithoutMetaTitleOrDescriptionIsSkippedWithItsReason(): void
    {
        $response = $this->runAction(
            'rankroute/seo/import',
            'Bearer correct-key',
            rawBody: json_encode([
                ['url' => 'https://rankroute.test/' . $this->entryUri],
            ]),
        );

        self::assertSame(0, $response->data['updated']);
        self::assertSame(1, $response->data['total']);
        self::assertSame(
            [['url' => 'https://rankroute.test/' . $this->entryUri, 'reason' => 'No meta_title or meta_description provided']],
            $response->data['skipped'],
        );
    }

    public function testItemWithAnUnknownUrlIsSkippedWithItsReasonAndTheResolvedUri(): void
    {
        $response = $this->runAction(
            'rankroute/seo/import',
            'Bearer correct-key',
            rawBody: json_encode([
                ['url' => 'https://rankroute.test/no-such-entry', 'meta_title' => 'Whatever'],
            ]),
        );

        self::assertSame(0, $response->data['updated']);
        self::assertSame(1, $response->data['total']);
        self::assertSame(
            [['url' => 'https://rankroute.test/no-such-entry', 'uri' => 'no-such-entry', 'reason' => 'No entry found']],
            $response->data['skipped'],
        );
    }

    /**
     * D7: multi-site resolution — a `/nl/` URL updates the nl site's element, not the
     * primary site's.
     */
    public function testANlUrlUpdatesTheNlSitesElementNotThePrimary(): void
    {
        $response = $this->runAction(
            'rankroute/seo/import',
            'Bearer correct-key',
            rawBody: json_encode([
                ['url' => 'https://rankroute.test/nl/' . $this->entryUri, 'meta_title' => 'Nederlandse titel'],
            ]),
        );

        self::assertSame(1, $response->data['updated']);

        $nlEntry = Craft::$app->getElements()->getElementById($this->entryId, Entry::class, $this->nlSiteId);
        self::assertSame('Nederlandse titel', $nlEntry->getFieldValue(self::SEOMATIC_HANDLE)->metaGlobalVars->seoTitle);

        $primaryEntry = Craft::$app->getElements()->getElementById($this->entryId, Entry::class, $this->primarySiteId);
        self::assertNotSame('Nederlandse titel', $primaryEntry->getFieldValue(self::SEOMATIC_HANDLE)->metaGlobalVars->seoTitle);
    }

    /**
     * D7: any element type, not only entries.
     */
    public function testACategoryUrlIsUpdated(): void
    {
        $response = $this->runAction(
            'rankroute/seo/import',
            'Bearer correct-key',
            rawBody: json_encode([
                ['url' => 'https://rankroute.test/topics/' . $this->categorySlug, 'meta_description' => 'A category description'],
            ]),
        );

        self::assertSame(1, $response->data['updated']);

        $category = Craft::$app->getElements()->getElementById($this->categoryId, Category::class, $this->primarySiteId);
        self::assertSame('A category description', $category->getFieldValue(self::SEOMATIC_HANDLE)->metaGlobalVars->seoDescription);
    }

    public function testAllItemsAreSkippedWhenNoUrlIsProvidedButPayloadShapesStillNormalise(): void
    {
        $response = $this->runAction(
            'rankroute/seo/import',
            'Bearer correct-key',
            rawBody: json_encode([
                'results' => [
                    ['url' => 'https://rankroute.test/' . $this->entryUri, 'meta_title' => 'Wrapped shape title'],
                ],
            ]),
        );

        self::assertSame(1, $response->data['updated']);
    }

    public function testSeomaticAbsentReturnsABadRequest(): void
    {
        Craft::$app->getPlugins()->disablePlugin('seomatic');

        try {
            $this->runAction(
                'rankroute/seo/import',
                'Bearer correct-key',
                rawBody: json_encode([
                    ['url' => 'https://rankroute.test/' . $this->entryUri, 'meta_title' => 'Whatever'],
                ]),
            );
            self::fail('Expected a BadRequestHttpException.');
        } catch (\yii\web\BadRequestHttpException $e) {
            self::assertSame(400, $e->statusCode);
            self::assertSame('SEOmatic is not installed', $e->getMessage());
        }
    }
}
