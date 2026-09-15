<?php

namespace lameco\rankroute\tests\unit;

use lameco\rankroute\services\SeoBulkService;
use PHPUnit\Framework\TestCase;
use yii\web\BadRequestHttpException;

/**
 * {@see SeoBulkService::normalizeItems()} against the three payload shapes n8n sends
 * (CONTEXT.md — Bulk meta item: flat array, `{results: [...]}`, `[{results: [...]}]`),
 * without booting Craft, exactly like {@see \lameco\rankroute\tests\unit\ElementResolverTest}.
 */
final class SeoBulkServiceTest extends TestCase
{
    private function service(): SeoBulkService
    {
        return new SeoBulkService();
    }

    public function testFlatArrayYieldsItsItemsUnchanged(): void
    {
        $items = $this->service()->normalizeItems(json_encode([
            ['url' => '/a', 'meta_title' => 'A'],
            ['url' => '/b', 'meta_title' => 'B'],
        ]));

        self::assertSame([
            ['url' => '/a', 'meta_title' => 'A'],
            ['url' => '/b', 'meta_title' => 'B'],
        ], $items);
    }

    public function testResultsWrappedObjectYieldsTheSameItems(): void
    {
        $items = $this->service()->normalizeItems(json_encode([
            'results' => [
                ['url' => '/a', 'meta_title' => 'A'],
            ],
        ]));

        self::assertSame([['url' => '/a', 'meta_title' => 'A']], $items);
    }

    public function testResultsWrappedInAOneElementArrayYieldsTheSameItems(): void
    {
        $items = $this->service()->normalizeItems(json_encode([
            ['results' => [
                ['url' => '/a', 'meta_title' => 'A'],
            ]],
        ]));

        self::assertSame([['url' => '/a', 'meta_title' => 'A']], $items);
    }

    public function testEmptyBodyIsABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('No JSON data provided in request body.');

        $this->service()->normalizeItems('');
    }

    public function testInvalidJsonIsABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('No results found in JSON data.');

        $this->service()->normalizeItems('not json');
    }

    public function testNonArrayJsonIsABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('No results found in JSON data.');

        $this->service()->normalizeItems('"just a string"');
    }

    public function testResultsKeyThatIsNotAnArrayIsABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('No results found in JSON data.');

        $this->service()->normalizeItems(json_encode(['results' => 'not-an-array']));
    }

    /**
     * A single item sent unwrapped is a JSON object, not a list. Iterating it would walk its
     * *values* and report one 'No url provided' skip per value with a 200, so the caller sees
     * success for a payload nothing was written from. craft-seo-import 1.0.4 answered 400.
     */
    public function testABareItemObjectIsABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('No results found in JSON data.');

        $this->service()->normalizeItems(json_encode(['url' => '/blog/one', 'meta_title' => 'T']));
    }
}
