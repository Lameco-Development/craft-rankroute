<?php

namespace lameco\rankroute\tests\integration;

use Craft;
use craft\db\Table;
use craft\elements\Category;
use craft\elements\Entry;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

/**
 * The export/import pipeline ported from craft-entry-optimizer 1.0.7 (issue #2). Fixtures
 * come from {@see ContentFixtureTestCase}, seeded inside each test's own rolled-back
 * transaction.
 */
final class OptimizerExportImportTest extends ContentFixtureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedContent();
        $this->setApiKey('correct-key');
    }

    public function testExportByIdReturnsAOneElementArrayWithMetadataTitleAndEveryCustomField(): void
    {
        $response = $this->runAction('rankroute/optimizer/export', 'Bearer correct-key', ['id' => $this->entryId]);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($response->data);
        self::assertCount(1, $response->data);

        $document = $response->data[0];

        self::assertSame([
            'id' => $this->entryId,
            'siteId' => $this->primarySiteId,
        ], $document['metadata']);
        self::assertSame('Original title', $document['title']);
        self::assertSame('Original body text', $document[self::PLAIN_TEXT_HANDLE]);
        self::assertSame(['value' => 'news', 'label' => 'News'], $document[self::DROPDOWN_HANDLE]);
        // Matrix field left empty on the fixture entry: exported as an empty array, not omitted.
        self::assertSame([], $document[self::MATRIX_HANDLE]);
    }

    public function testExportBySlugWithALocalePrefixResolvesToTheSecondSite(): void
    {
        $response = $this->runAction(
            'rankroute/optimizer/export',
            'Bearer correct-key',
            ['slug' => 'nl/' . $this->entryUri],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($this->nlSiteId, $response->data[0]['metadata']['siteId']);
    }

    public function testExportOfACategoryByItsUriWorks(): void
    {
        $response = $this->runAction(
            'rankroute/optimizer/export',
            'Bearer correct-key',
            ['slug' => 'topics/' . $this->categorySlug],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($this->categoryId, $response->data[0]['metadata']['id']);
        self::assertSame('Original category', $response->data[0]['title']);
    }

    /**
     * CHANGELOG 1.0.2: a multi-segment URI resolves, not only a bare slug.
     */
    public function testSlugContainingSlashesResolves(): void
    {
        $response = $this->runAction(
            'rankroute/optimizer/export',
            'Bearer correct-key',
            ['slug' => $this->entryUri],
        );

        self::assertStringContainsString('/', $this->entryUri);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($this->entryId, $response->data[0]['metadata']['id']);
    }

    public function testExportWithNeitherIdNorSlugIsABadRequest(): void
    {
        // The harness boots without Craft's ErrorHandler (IntegrationTestCase), so an
        // uncaught HTTP exception propagates instead of becoming a JSON error response.
        try {
            $this->runAction('rankroute/optimizer/export', 'Bearer correct-key');
            self::fail('Expected a BadRequestHttpException.');
        } catch (BadRequestHttpException $e) {
            self::assertSame(400, $e->statusCode);
            self::assertSame('An element ID or slug is required.', $e->getMessage());
        }
    }

    public function testExportOfAnUnknownSlugIsNotFound(): void
    {
        try {
            $this->runAction(
                'rankroute/optimizer/export',
                'Bearer correct-key',
                ['slug' => 'no-such-entry'],
            );
            self::fail('Expected a NotFoundHttpException.');
        } catch (NotFoundHttpException $e) {
            self::assertSame(404, $e->statusCode);
        }
    }

    public function testImportOfTheUnmodifiedExportReportsNoChangesAndCreatesNoDraft(): void
    {
        $exportResponse = $this->runAction('rankroute/optimizer/export', 'Bearer correct-key', ['id' => $this->entryId]);
        $document = $exportResponse->data[0];

        $response = $this->runAction(
            'rankroute/optimizer/import',
            'Bearer correct-key',
            rawBody: json_encode([$document]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'success' => true,
            'message' => 'No changes detected',
            'entryId' => $this->entryId,
            'updatedFields' => [],
        ], $response->data);

        $draftCount = (int)Craft::$app->getDb()->createCommand(
            'SELECT COUNT(*) FROM ' . Table::DRAFTS . ' WHERE [[canonicalId]] = :id',
            ['id' => $this->entryId],
        )->queryScalar();
        self::assertSame(0, $draftCount);
    }

    public function testImportWithChangedTitleAndPlainTextFieldCreatesADraftAndLeavesTheLiveElementUntouched(): void
    {
        $exportResponse = $this->runAction('rankroute/optimizer/export', 'Bearer correct-key', ['id' => $this->entryId]);
        $document = $exportResponse->data[0];
        $document['title'] = 'Rewritten title';
        $document[self::PLAIN_TEXT_HANDLE] = 'Rewritten body text';

        $response = $this->runAction(
            'rankroute/optimizer/import',
            'Bearer correct-key',
            rawBody: json_encode([$document]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(true, $response->data['success']);
        self::assertSame($this->entryId, $response->data['entryId']);
        self::assertEqualsCanonicalizing(['title', self::PLAIN_TEXT_HANDLE], $response->data['updatedFields']);
        self::assertNotNull($response->data['draftId']);
        self::assertNotNull($response->data['cpEditUrl']);

        // $response->data['draftId'] is the {{%drafts}} row id (Element::$draftId), not the
        // element id, so the draft is looked up through the drafts-aware entry query.
        $draft = Entry::find()->draftId($response->data['draftId'])->siteId($this->primarySiteId)->one();
        self::assertNotNull($draft);
        self::assertSame('Rewritten title', $draft->title);
        self::assertSame('Rewritten body text', $draft->getFieldValue(self::PLAIN_TEXT_HANDLE));

        $live = Craft::$app->getElements()->getElementById($this->entryId, null, $this->primarySiteId);
        self::assertSame('Original title', $live->title);
        self::assertSame('Original body text', $live->getFieldValue(self::PLAIN_TEXT_HANDLE));
    }

    public function testImportWithAnUnknownMetadataIdIsNotFound(): void
    {
        try {
            $this->runAction(
                'rankroute/optimizer/import',
                'Bearer correct-key',
                rawBody: json_encode([[
                    'metadata' => ['id' => 999999999, 'siteId' => $this->primarySiteId],
                    'title' => 'Whatever',
                ]]),
            );
            self::fail('Expected a NotFoundHttpException.');
        } catch (NotFoundHttpException $e) {
            self::assertSame(404, $e->statusCode);
        }
    }

    public function testImportWithAnEmptyBodyIsABadRequest(): void
    {
        try {
            $this->runAction('rankroute/optimizer/import', 'Bearer correct-key', rawBody: '');
            self::fail('Expected a BadRequestHttpException.');
        } catch (BadRequestHttpException $e) {
            self::assertSame(400, $e->statusCode);
            self::assertSame('No JSON data provided in request body.', $e->getMessage());
        }
    }

    public function testImportWithoutAnElementIdIsABadRequest(): void
    {
        try {
            $this->runAction(
                'rankroute/optimizer/import',
                'Bearer correct-key',
                rawBody: json_encode([['title' => 'No metadata here']]),
            );
            self::fail('Expected a BadRequestHttpException.');
        } catch (BadRequestHttpException $e) {
            self::assertSame(400, $e->statusCode);
            self::assertStringContainsString('Element ID not found in import data.', $e->getMessage());
        }
    }

    /**
     * metadata.siteId selects the site, rather than the import falling back to site 1.
     */
    public function testImportAppliesToTheSiteNamedInTheMetadata(): void
    {
        $exportResponse = $this->runAction(
            'rankroute/optimizer/export',
            'Bearer correct-key',
            ['slug' => 'nl/' . $this->entryUri],
        );
        $document = $exportResponse->data[0];
        self::assertSame($this->nlSiteId, $document['metadata']['siteId']);

        $document['title'] = 'Herschreven titel';

        $response = $this->runAction(
            'rankroute/optimizer/import',
            'Bearer correct-key',
            rawBody: json_encode([$document]),
        );

        self::assertSame(['title'], $response->data['updatedFields']);

        $draft = Entry::find()->draftId($response->data['draftId'])->siteId($this->nlSiteId)->one();
        self::assertNotNull($draft);
        self::assertSame('Herschreven titel', $draft->title);
    }

    /**
     * Import is element-type agnostic too, and the response keeps the `entryId` key for a
     * non-entry element (ADR 0001).
     */
    public function testImportOfACategoryCreatesADraft(): void
    {
        $exportResponse = $this->runAction(
            'rankroute/optimizer/export',
            'Bearer correct-key',
            ['slug' => 'topics/' . $this->categorySlug],
        );
        $document = $exportResponse->data[0];
        $document['title'] = 'Rewritten category';

        $response = $this->runAction(
            'rankroute/optimizer/import',
            'Bearer correct-key',
            rawBody: json_encode([$document]),
        );

        self::assertSame($this->categoryId, $response->data['entryId']);
        self::assertNotNull($response->data['draftId']);

        $draft = Category::find()->draftId($response->data['draftId'])->siteId($this->primarySiteId)->one();
        self::assertNotNull($draft);
        self::assertSame('Rewritten category', $draft->title);
    }

    /**
     * ADR 0002: the legacy alias must produce the same document as the new path. One
     * assertion is enough — the dispatch itself is covered by ActionDispatchTest.
     */
    public function testLegacyExportPathReturnsTheSameDocumentAsTheNewPath(): void
    {
        $newResponse = $this->runAction('rankroute/optimizer/export', 'Bearer correct-key', ['id' => $this->entryId]);
        $legacyResponse = $this->runAction(
            '_craft-entry-optimizer/optimized-entry/export',
            'Bearer correct-key',
            ['id' => $this->entryId],
        );

        self::assertSame(200, $legacyResponse->getStatusCode());
        self::assertSame($newResponse->data, $legacyResponse->data);
    }
}
