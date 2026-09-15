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
}
