<?php

namespace lameco\rankroute\tests\unit;

use lameco\rankroute\services\text\HtmlSkeleton;
use lameco\rankroute\services\text\PlaceholderImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Inline images of a new page: asset reference tags in HTML point at the placeholder.
 */
final class PlaceholderImageTest extends TestCase
{
    private const URL = 'https://example.com/uploads/rankroute-placeholder.png';

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function referenceProvider(): array
    {
        return [
            'url with fallback' => [
                '<p><img src="{asset:12:url||https://example.com/uploads/hero.jpg}" alt="Hero"></p>',
                '<p><img src="{asset:77:url||' . self::URL . '}" alt="Hero"></p>',
            ],
            'without fallback' => [
                '<img src="{asset:12:url}">',
                '<img src="{asset:77:url||' . self::URL . '}">',
            ],
            'site-specific reference' => [
                '<img src="{asset:12@2:url||https://example.com/nl/hero.jpg}">',
                '<img src="{asset:77:url||' . self::URL . '}">',
            ],
            'transform keeps the transform' => [
                '<img src="{asset:12:transform:hero||https://example.com/_hero/hero.jpg}">',
                '<img src="{asset:77:transform:hero||' . self::URL . '}">',
            ],
            'every reference, srcset included' => [
                '<img src="{asset:12:url}" srcset="{asset:12:transform:small} 480w, {asset:13:transform:large} 1200w">',
                '<img src="{asset:77:url||' . self::URL . '}" srcset="{asset:77:transform:small||' . self::URL . '} 480w, {asset:77:transform:large||' . self::URL . '} 1200w">',
            ],
            'other reference tags stay' => [
                '<a href="{entry:6@1:url||https://example.com/contact}">Contact</a>',
                '<a href="{entry:6@1:url||https://example.com/contact}">Contact</a>',
            ],
            'literal image URL stays' => [
                '<img src="https://example.com/uploads/hero.jpg">',
                '<img src="https://example.com/uploads/hero.jpg">',
            ],
        ];
    }

    #[DataProvider('referenceProvider')]
    public function testAssetReferencesPointAtThePlaceholder(string $html, string $expected): void
    {
        self::assertSame($expected, PlaceholderImage::replaceAssetReferences($html, 77, self::URL));
        self::assertSame($html !== $expected, PlaceholderImage::hasAssetReferences($html));
    }

    public function testWithoutAUrlTheFallbackIsDropped(): void
    {
        self::assertSame('<img src="{asset:77:url}">', PlaceholderImage::replaceAssetReferences('<img src="{asset:12:url||https://x/y.jpg}">', 77, null));
    }

    public function testOnlyTheReferenceChangesInTheTagSkeleton(): void
    {
        $html = '<figure><img src="{asset:12:url||https://x/hero.jpg}" alt="Hero"><figcaption>Our hall</figcaption></figure>';
        $replaced = PlaceholderImage::replaceAssetReferences($html, 77, self::URL);

        self::assertSame(HtmlSkeleton::textContent($html), HtmlSkeleton::textContent($replaced));
        self::assertTrue(HtmlSkeleton::equals(
            PlaceholderImage::replaceAssetReferences($html, 77, null),
            $replaced,
            relaxedReferenceTags: true,
        ));
    }
}
