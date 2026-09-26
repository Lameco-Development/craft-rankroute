<?php

namespace lameco\rankroute\tests\unit;

use lameco\rankroute\services\TextTemplatesService;
use PHPUnit\Framework\TestCase;

/**
 * The order of `text/templates`: most live entries first, then section name, then entry
 * type name, then the handles.
 */
final class TextTemplatesSortTest extends TestCase
{
    public function testMostLiveEntriesComeFirst(): void
    {
        $sorted = TextTemplatesService::sort([
            self::template('a', 'A', 'x', 'X', 3),
            self::template('b', 'B', 'y', 'Y', 42),
            self::template('c', 'C', 'z', 'Z', 1),
        ]);

        self::assertSame([42, 3, 1], array_column($sorted, 'liveEntries'));
    }

    public function testATieIsOrderedBySectionNameThenEntryTypeName(): void
    {
        $sorted = TextTemplatesService::sort([
            self::template('pages', 'Pagina\'s', 'page', 'Pagina', 5),
            self::template('articles', 'artikelen', 'news', 'Nieuws', 5),
            self::template('articles', 'artikelen', 'article', 'Artikel', 5),
            self::template('blog', 'Blog 10', 'post', 'Post', 5),
            self::template('blog2', 'Blog 9', 'post', 'Post', 5),
        ]);

        // Case-insensitive and natural: "artikelen" before "Blog 9" before "Blog 10".
        self::assertSame(
            [['articles', 'article'], ['articles', 'news'], ['blog2', 'post'], ['blog', 'post'], ['pages', 'page']],
            array_map(fn(array $template) => [$template['section']['handle'], $template['entryType']['handle']], $sorted),
        );
    }

    public function testEqualNamesFallBackToTheHandles(): void
    {
        $sorted = TextTemplatesService::sort([
            self::template('news2', 'News', 'b', 'Item', 1),
            self::template('news1', 'News', 'b', 'Item', 1),
            self::template('news1', 'News', 'a', 'Item', 1),
        ]);

        self::assertSame(
            [['news1', 'a'], ['news1', 'b'], ['news2', 'b']],
            array_map(fn(array $template) => [$template['section']['handle'], $template['entryType']['handle']], $sorted),
        );
    }

    public function testAnEmptyListStaysEmpty(): void
    {
        self::assertSame([], TextTemplatesService::sort([]));
    }

    /**
     * @return array{section: array{handle: string, name: string, type: string}, entryType: array{handle: string, name: string}, liveEntries: int, samples: list<array{elementId: int, title: string, url: string}>}
     */
    private static function template(string $sectionHandle, string $sectionName, string $typeHandle, string $typeName, int $liveEntries): array
    {
        return [
            'section' => ['handle' => $sectionHandle, 'name' => $sectionName, 'type' => 'channel'],
            'entryType' => ['handle' => $typeHandle, 'name' => $typeName],
            'liveEntries' => $liveEntries,
            'samples' => [['elementId' => 1, 'title' => 'Sample', 'url' => 'https://example.com/sample']],
        ];
    }
}
