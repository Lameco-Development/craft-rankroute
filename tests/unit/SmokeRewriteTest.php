<?php

namespace lameco\rankroute\tests\unit;

use lameco\rankroute\services\text\HtmlSkeleton;
use lameco\rankroute\services\text\SmokeRewrite;
use PHPUnit\Framework\TestCase;

final class SmokeRewriteTest extends TestCase
{
    public function testPlainAppendsTheMark(): void
    {
        self::assertSame('Applications ✓', SmokeRewrite::plain('Applications', null));
    }

    public function testPlainStaysWithinMaxLength(): void
    {
        $rewritten = SmokeRewrite::plain('1234567890', 10);

        self::assertSame(10, mb_strlen($rewritten));
        self::assertStringEndsWith('✓', $rewritten);
    }

    public function testHtmlMarksTheLastTextOfEveryBlock(): void
    {
        self::assertSame(
            '<p>Read <a href="/x">more ✓</a> </p><ul><li>One ✓</li><li><strong>Two ✓</strong> </li></ul>',
            SmokeRewrite::html('<p>Read <a href="/x">more</a> </p><ul><li>One</li><li><strong>Two</strong> </li></ul>'),
        );
    }

    public function testHtmlWithoutBlocksMarksTheLastText(): void
    {
        self::assertSame('Hello <b>world ✓</b>', SmokeRewrite::html('Hello <b>world</b>'));
    }

    public function testHtmlRewriteKeepsTheSkeleton(): void
    {
        $html = "<h2>Title</h2>\n<p>Fish &amp; chips<br>&nbsp;</p><!-- note --><p><img src=\"/a.jpg\" alt=\"a\"></p>";
        $rewritten = SmokeRewrite::html($html);

        self::assertNotSame($html, $rewritten);
        self::assertTrue(HtmlSkeleton::equals($html, $rewritten));
        self::assertSame('<h2>Title ✓</h2>', substr($rewritten, 0, strlen('<h2>Title ✓</h2>')));
    }

    public function testChangedHrefBreaksTheSkeleton(): void
    {
        $html = '<p><a href="/x">go</a> <a href="/y">there</a></p>';
        $changed = SmokeRewrite::withChangedHref($html);

        self::assertNotNull($changed);
        self::assertFalse(HtmlSkeleton::equals($html, $changed));
        self::assertNull(SmokeRewrite::withChangedHref('<p>no links</p>'));
    }
}
