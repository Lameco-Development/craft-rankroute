<?php

namespace lameco\rankroute\tests\unit;

use InvalidArgumentException;
use lameco\rankroute\services\text\TextAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextAddressTest extends TestCase
{
    public function testParsesATopLevelTitle(): void
    {
        $address = TextAddress::parse('title');

        self::assertSame([], $address->entries);
        self::assertSame('title', $address->leaf);
        self::assertTrue($address->isTitle());
        self::assertFalse($address->isSeo());
    }

    public function testParsesSeoMeta(): void
    {
        self::assertTrue(TextAddress::parse('seo.seoTitle')->isSeo());
        self::assertTrue(TextAddress::parse('seo.seoDescription')->isSeo());
    }

    public function testParsesNestedEntrySteps(): void
    {
        $address = TextAddress::parse('pageBuilder[6].items[1].title');

        self::assertSame([
            ['handle' => 'pageBuilder', 'index' => 6],
            ['handle' => 'items', 'index' => 1],
        ], $address->entries);
        self::assertSame('title', $address->leaf);
    }

    public function testParsesFourLevelsDeep(): void
    {
        $address = TextAddress::parse('pageBuilder[0].items[12].contentBuilder[3].buttons[0].label');

        self::assertCount(4, $address->entries);
        self::assertSame('label', $address->leaf);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function roundTripProvider(): array
    {
        return [
            'title' => ['title'],
            'field' => ['headerTitle'],
            'seo' => ['seo.seoDescription'],
            'nested field' => ['pageBuilder[3].content'],
            'deep title' => ['pageBuilder[6].items[1].title'],
            'handle with digits and underscore' => ['block_2[0].text2'],
        ];
    }

    #[DataProvider('roundTripProvider')]
    public function testFormatIsTheInverseOfParse(string $id): void
    {
        self::assertSame($id, TextAddress::parse($id)->toString());
        self::assertSame($id, (string)TextAddress::parse($id));
    }

    public function testBuildsNestedAddresses(): void
    {
        $address = (new TextAddress([], 'title'))
            ->withEntry('pageBuilder', 6)
            ->withEntry('items', 1)
            ->withLeaf('title');

        self::assertSame('pageBuilder[6].items[1].title', $address->toString());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedProvider(): array
    {
        return [
            'empty' => [''],
            'missing leaf' => ['pageBuilder[0]'],
            'trailing dot' => ['pageBuilder[0].'],
            'negative index' => ['pageBuilder[-1].title'],
            'non-numeric index' => ['pageBuilder[a].title'],
            'unknown dotted leaf' => ['seo.ogTitle'],
            'nested seo' => ['pageBuilder[0].seo.seoTitle'],
            'leading digit handle' => ['1field'],
            'whitespace' => [' title'],
            'double dot' => ['a[0]..title'],
        ];
    }

    #[DataProvider('malformedProvider')]
    public function testRejectsMalformedAddresses(string $id): void
    {
        self::assertFalse(TextAddress::isValid($id));

        $this->expectException(InvalidArgumentException::class);
        TextAddress::parse($id);
    }
}
