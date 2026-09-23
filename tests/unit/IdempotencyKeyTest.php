<?php

namespace lameco\rankroute\tests\unit;

use lameco\rankroute\services\TextImportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Review rule R7: the optional `idempotencyKey` of a text import.
 */
final class IdempotencyKeyTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function keyProvider(): array
    {
        return [
            'backend key' => ['run-page-123', true],
            'all allowed characters' => ['Az09._:-', true],
            'one character' => ['a', true],
            '64 characters' => [str_repeat('k', 64), true],
            'empty' => ['', false],
            '65 characters' => [str_repeat('k', 65), false],
            'space' => ['run page', false],
            'slash' => ['run/page', false],
            'percent (LIKE wildcard)' => ['run%', false],
            'newline' => ["run\n", false],
            'non-ascii' => ['rün', false],
            'integer' => [123, false],
            'array' => [['a'], false],
        ];
    }

    #[DataProvider('keyProvider')]
    public function testKeyValidation(mixed $key, bool $valid): void
    {
        self::assertSame($valid, TextImportService::isValidIdempotencyKey($key));
    }

    public function testDraftNotesCarryTheKeyThenSiteAndChangedItems(): void
    {
        self::assertSame(
            "rankroute:run-page-1\n{\"siteId\":2,\"changedItems\":[\"title\",\"pageBuilder[0].content\"]}",
            TextImportService::draftNotes('run-page-1', 2, ['title', 'pageBuilder[0].content']),
        );
    }
}
