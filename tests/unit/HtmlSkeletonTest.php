<?php

namespace lameco\rankroute\tests\unit;

use lameco\rankroute\services\text\HtmlSkeleton;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The tag skeleton is the whole safety net for `html` text items: equal skeletons mean
 * only text changed, so every case here is either "text-only, must be equal" or "markup
 * changed, must differ".
 */
final class HtmlSkeletonTest extends TestCase
{
    public function testTokensListTagsInOrderWithoutText(): void
    {
        self::assertSame(
            ['<p>', '<strong>', '</strong>', '<a href="/x">', '</a>', '</p>'],
            HtmlSkeleton::tokens('<p>Hello <strong>big</strong> world, <a href="/x">go</a>.</p>'),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function equivalentProvider(): array
    {
        return [
            'text change' => ['<p>Old text</p>', '<p>Completely new text</p>'],
            'text change inside nested inline tags' => [
                '<p>A <strong>bold <em>and italic</em></strong> claim</p>',
                '<p>Another <strong>strong <em>emphasised</em></strong> statement</p>',
            ],
            'void tag spellings' => ['<p>a<br>b<br/>c<br />d</p>', '<p>x<br />y<br>z<br/>w</p>'],
            'attribute order' => ['<a href="/x" target="_blank">a</a>', '<a target="_blank" href="/x">b</a>'],
            'attribute quoting' => ['<a href="/x" class=\'btn\'>a</a>', '<a href=/x class="btn">b</a>'],
            'entities in attribute values' => ['<a href="/x?a=1&amp;b=2">a</a>', '<a href="/x?a=1&b=2">b</a>'],
            'entities in text' => ['<p>Fish &amp; chips</p>', '<p>Fish & chips &nbsp;</p>'],
            'whitespace inside tags' => ['<a  href = "/x" >a</a >', '<a href="/x">b</a>'],
            'whitespace between tags' => ["<ul>\n  <li>a</li>\n</ul>", '<ul><li>b</li></ul>'],
            'tag name case' => ['<P>a</P>', '<p>b</p>'],
            'text around an unchanged comment' => ['<p>a<!-- note --></p>', '<p>b<!-- note --></p>'],
            'text around unchanged script' => ['<p>a</p><script>var a = 1;</script>', '<p>b</p><script>var a = 1;</script>'],
            'literal less-than in text' => ['<p>a < b</p>', '<p>c < d and more</p>'],
        ];
    }

    #[DataProvider('equivalentProvider')]
    public function testTextOnlyDifferencesKeepTheSkeletonEqual(string $a, string $b): void
    {
        self::assertTrue(HtmlSkeleton::equals($a, $b), json_encode([HtmlSkeleton::tokens($a), HtmlSkeleton::tokens($b)]));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function changedProvider(): array
    {
        return [
            'changed href' => ['<p><a href="/x">go</a></p>', '<p><a href="/y">go</a></p>'],
            'other reference tag' => ['<a href="{entry:6@1:url||/x}">go</a>', '<a href="{entry:7@1:url||/x}">go</a>'],
            'added attribute' => ['<p><a href="/x">go</a></p>', '<p><a href="/x" rel="nofollow">go</a></p>'],
            'removed link' => ['<p><a href="/x">go</a></p>', '<p>go</p>'],
            'added tag' => ['<p>plain</p>', '<p><strong>plain</strong></p>'],
            'renamed tag' => ['<h2>Title</h2>', '<h3>Title</h3>'],
            'split paragraph' => ['<p>one two</p>', '<p>one</p><p>two</p>'],
            'removed void tag' => ['<p>a<br>b</p>', '<p>a b</p>'],
            'added comment' => ['<p>a</p>', '<p>a<!-- x --></p>'],
            'changed comment content (R4)' => ['<p>a<!-- note --></p>', '<p>a<!-- other note --></p>'],
            'comment smuggling (R4)' => [
                '<p>A</p><!-- x --><p>B</p>',
                '<p>A</p><!--><script>alert(1)</script><!-- --><p>B</p>',
            ],
            'comment closed by --!> (R4)' => ['<p>A</p><!-- x --><p>B</p>', '<p>A</p><!-- x --!><img src=x onerror=alert(1)><!-- --><p>B</p>'],
            'changed cdata (R4)' => ['<p><![CDATA[ a ]]></p>', '<p><![CDATA[ b ]]></p>'],
            'changed doctype case (R4)' => ['<!DOCTYPE html><p>a</p>', '<!doctype html><p>a</p>'],
            'changed bogus comment (R4)' => ['<p>a<?x?></p>', '<p>a<?y?></p>'],
            'changed end-tag bogus comment (R4)' => ['<p>a</ x></p>', '<p>a</ y></p>'],
            'reference tag fallback url, strict (R3)' => [
                '<p><a href="{entry:6@1:url||https://orig.example}">go</a></p>',
                '<p><a href="{entry:6@1:url||https://evil.example}">go</a></p>',
            ],
            'textarea content (R5)' => ['<textarea>a</textarea>', '<textarea>b</textarea>'],
            'title content (R5)' => ['<title>a</title>', '<title>b</title>'],
            'xmp content (R5)' => ['<xmp>a</xmp>', '<xmp>b</xmp>'],
            'iframe content (R5)' => ['<iframe>a</iframe>', '<iframe>b</iframe>'],
            'noembed content (R5)' => ['<noembed>a</noembed>', '<noembed>b</noembed>'],
            'noframes content (R5)' => ['<noframes>a</noframes>', '<noframes>b</noframes>'],
            'noscript content (R5)' => ['<noscript>a</noscript>', '<noscript>b</noscript>'],
            'style content (R5)' => ['<style>p{}</style>', '<style>p{color:red}</style>'],
            'changed image src' => ['<img src="/a.jpg" alt="a">', '<img src="/b.jpg" alt="a">'],
            'changed alt attribute' => ['<img src="/a.jpg" alt="a">', '<img src="/a.jpg" alt="b">'],
            'script content' => ['<script>var a = 1;</script>', '<script>var a = 2;</script>'],
            'self-closed non-void vs open' => ['<span/>text', '<span>text'],
        ];
    }

    #[DataProvider('changedProvider')]
    public function testMarkupChangesMakeTheSkeletonDiffer(string $a, string $b): void
    {
        self::assertFalse(HtmlSkeleton::equals($a, $b), json_encode([HtmlSkeleton::tokens($a), HtmlSkeleton::tokens($b)]));
    }

    public function testRelaxedModeIgnoresOnlyTheReferenceTagFallback(): void
    {
        $original = '<p><a href="{entry:6@1:url||https://orig.example}">go</a></p>';

        self::assertTrue(HtmlSkeleton::equals($original, '<p><a href="{entry:6@1:url||https://evil.example}">x</a></p>', relaxedReferenceTags: true));
        self::assertFalse(HtmlSkeleton::equals($original, '<p><a href="{entry:7@1:url||https://orig.example}">x</a></p>', relaxedReferenceTags: true));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function commentProvider(): array
    {
        return [
            'abrupt <!-->' => ['<!--><b>', ['<!-->', '<b>']],
            'abrupt <!--->' => ['<!---><b>', ['<!--->', '<b>']],
            'empty <!---->' => ['<!----><b>', ['<!---->', '<b>']],
            'closed by -->' => ['<!-- a <b> --><i>', ['<!-- a <b> -->', '<i>']],
            'closed by --!>' => ['<!-- a --!><i>', ['<!-- a --!>', '<i>']],
            'earliest terminator wins' => ['<!-- a --!> b --><i>', ['<!-- a --!>', '<i>']],
            'unterminated runs to the end' => ['<!-- a <b>', ['<!-- a <b>']],
            'cdata is a bogus comment up to the first >' => ['<![CDATA[ a ]]><i>', ['<![CDATA[ a ]]>', '<i>']],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('commentProvider')]
    public function testCommentsEndTheWayBrowsersEndThem(string $html, array $expected): void
    {
        self::assertSame($expected, HtmlSkeleton::tokens($html));
    }

    public function testRawTextElementContentIsOneTokenUntilItsOwnEndTag(): void
    {
        self::assertSame(
            ['<script>', '#raw:if (a </b> c) {}', '</script>', '<p>', '</p>'],
            HtmlSkeleton::tokens('<script>if (a </b> c) {}</SCRIPT ><p>x</p>'),
        );
        self::assertSame(['<textarea>', '#raw:<b>not a tag</b>', '</textarea>'], HtmlSkeleton::tokens('<textarea><b>not a tag</b></textarea>'));
    }

    public function testSelfClosedNonVoidElementBecomesAnOpeningAndClosingToken(): void
    {
        self::assertSame(['<span>', '</span>'], HtmlSkeleton::tokens('<span/>'));
        self::assertSame(['<br>'], HtmlSkeleton::tokens('<br/>'));
    }

    public function testDuplicateAttributeKeepsTheFirstValue(): void
    {
        self::assertSame(['<a href="/first">', '</a>'], HtmlSkeleton::tokens('<a href="/first" href="/second">x</a>'));
    }

    public function testContainsMarkupDetectsAnythingThatCouldStartMarkup(): void
    {
        self::assertTrue(HtmlSkeleton::containsMarkup('Hello <b>world</b>'));
        self::assertTrue(HtmlSkeleton::containsMarkup('Hello <br/>'));
        self::assertTrue(HtmlSkeleton::containsMarkup('Hello <!-- x -->'));
        // R6: no closing ">" needed.
        self::assertTrue(HtmlSkeleton::containsMarkup('x <img src=x onerror=alert(1)//'));
        self::assertTrue(HtmlSkeleton::containsMarkup('a </b'));
        self::assertTrue(HtmlSkeleton::containsMarkup('a <!x'));
        self::assertTrue(HtmlSkeleton::containsMarkup('a <?x'));
        self::assertFalse(HtmlSkeleton::containsMarkup('3 < 4 and 5 > 2'));
        self::assertFalse(HtmlSkeleton::containsMarkup('a <3 b'));
        self::assertFalse(HtmlSkeleton::containsMarkup('Fish & chips'));
    }

    public function testTextContentStripsTagsAndDecodesEntities(): void
    {
        self::assertSame('Fish & chips now', HtmlSkeleton::textContent('<p>Fish &amp; <a href="/x">chips</a>&nbsp;now<!-- c --></p>'));
        self::assertSame('', trim(HtmlSkeleton::textContent('<p>&nbsp;</p><p><br></p>')));
    }
}
