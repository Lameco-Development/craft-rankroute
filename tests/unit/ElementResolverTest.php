<?php

namespace lameco\rankroute\tests\unit;

use craft\models\Site;
use lameco\rankroute\services\ElementResolver;
use PHPUnit\Framework\TestCase;

/**
 * {@see ElementResolver::resolve()} against an injected site list, so these run without
 * booting Craft. The algorithm is a verbatim extraction from craft-entry-optimizer 1.0.7's
 * `OptimizedEntryController::resolveSlug()`.
 */
final class ElementResolverTest extends TestCase
{
    private function site(int $id, string $baseUrl, bool $primary = false): Site
    {
        return new Site([
            'id' => $id,
            'handle' => 'site' . $id,
            'primary' => $primary,
            'baseUrl' => $baseUrl,
        ]);
    }

    public function testBarePathOnASiteWithNoBasePathResolvesToThePrimarySite(): void
    {
        $primary = $this->site(1, 'https://x.test/', primary: true);
        $resolver = new ElementResolver([$primary]);

        self::assertSame(
            ['uri' => 'projecten', 'siteId' => 1],
            $resolver->resolve('projecten'),
        );
    }

    public function testLocalePrefixedPathResolvesToTheMatchingSite(): void
    {
        $primary = $this->site(1, 'https://x.test/', primary: true);
        $nl = $this->site(2, 'https://x.test/nl/');
        $resolver = new ElementResolver([$primary, $nl]);

        self::assertSame(
            ['uri' => 'projecten', 'siteId' => 2],
            $resolver->resolve('nl/projecten'),
        );
    }

    public function testFullUrlWithLocalePrefixResolvesTheSameAsTheBarePath(): void
    {
        $primary = $this->site(1, 'https://x.test/', primary: true);
        $nl = $this->site(2, 'https://x.test/nl/');
        $resolver = new ElementResolver([$primary, $nl]);

        self::assertSame(
            ['uri' => 'projecten', 'siteId' => 2],
            $resolver->resolve('https://x.test/nl/projecten'),
        );
    }

    public function testPathEqualToTheBasePathResolvesToHome(): void
    {
        $primary = $this->site(1, 'https://x.test/', primary: true);
        $nl = $this->site(2, 'https://x.test/nl/');
        $resolver = new ElementResolver([$primary, $nl]);

        self::assertSame(
            ['uri' => '__home__', 'siteId' => 2],
            $resolver->resolve('nl'),
        );
    }

    public function testEmptyPathResolvesToThePrimarySiteHome(): void
    {
        $primary = $this->site(1, 'https://x.test/', primary: true);
        $nl = $this->site(2, 'https://x.test/nl/');
        $resolver = new ElementResolver([$primary, $nl]);

        self::assertSame(
            ['uri' => '__home__', 'siteId' => 1],
            $resolver->resolve(''),
        );
        self::assertSame(
            ['uri' => '__home__', 'siteId' => 1],
            $resolver->resolve('/'),
        );
    }

    public function testLongestBasePathPrefixWins(): void
    {
        $primary = $this->site(1, 'https://x.test/', primary: true);
        $nl = $this->site(2, 'https://x.test/nl/');
        $nlBlog = $this->site(3, 'https://x.test/nl/blog/');
        $resolver = new ElementResolver([$primary, $nl, $nlBlog]);

        self::assertSame(
            ['uri' => 'post', 'siteId' => 3],
            $resolver->resolve('nl/blog/post'),
        );
    }

    public function testNoPrefixMatchFallsBackToThePrimarySiteWithThePathUntouched(): void
    {
        $primary = $this->site(1, 'https://x.test/', primary: true);
        $nl = $this->site(2, 'https://x.test/nl/');
        $de = $this->site(3, 'https://x.test/de/');
        $resolver = new ElementResolver([$primary, $nl, $de]);

        self::assertSame(
            ['uri' => 'fr/unknown', 'siteId' => 1],
            $resolver->resolve('fr/unknown'),
        );
    }
}
