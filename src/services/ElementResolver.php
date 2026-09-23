<?php

namespace lameco\rankroute\services;

use Craft;
use craft\base\Component;
use craft\models\Site;

/**
 * Resolves a URL or path to a site and element URI by matching against each site's
 * base-URL path, longest prefix first. Extracted from craft-entry-optimizer 1.0.7's
 * `OptimizedEntryController::resolveSlug()`, so the optimizer export flow, the bulk meta
 * flow and the text flow (CONTEXT.md: Resolution) share it.
 *
 * A full URL whose host (ignoring case, port and a leading `www.`) is the host of one or
 * more sites' base URLs only matches those sites, so a site on its own domain (`https://example.de/…`) resolves to that site
 * instead of the primary one. A path, or a URL on a host no site has, resolves as before.
 */
class ElementResolver extends Component
{
    /**
     * @param Site[]|null $sites Sites to resolve against. Defaults to every site in the
     *     installation; tests can pass a fixed list to resolve without booting the database.
     */
    public function __construct(
        private readonly ?array $sites = null,
        $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * @return array{siteId: int, uri: string}
     */
    public function resolve(string $urlOrPath): array
    {
        $host = preg_match('#^https?://([^/?\#]+)#i', $urlOrPath, $match) ? self::host($match[1]) : null;

        // Strip any leading/trailing slashes and any protocol/domain if present
        $path = preg_replace('#^https?://[^/]+/?#', '', $urlOrPath);
        $path = trim($path, '/');

        $sites = $this->getSites();

        if ($host !== null) {
            $sitesOnHost = array_values(array_filter(
                $sites,
                fn(Site $site) => self::host((string)parse_url((string)$site->getBaseUrl(), PHP_URL_HOST)) === $host,
            ));

            if ($sitesOnHost !== []) {
                $sites = $sitesOnHost;
            }
        }

        // Extract path prefixes from site base URLs sorted by length descending
        // so more specific paths match first (e.g. /nl/ matches before /)
        $sitePaths = [];
        foreach ($sites as $site) {
            $basePath = trim(parse_url($site->getBaseUrl(), PHP_URL_PATH) ?? '', '/');
            $sitePaths[] = ['site' => $site, 'basePath' => $basePath];
        }

        usort($sitePaths, function($a, $b) {
            return strlen($b['basePath']) - strlen($a['basePath']);
        });

        foreach ($sitePaths as $entry) {
            $basePath = $entry['basePath'];
            if ($basePath === '') {
                continue;
            }

            $prefix = $basePath . '/';
            if (str_starts_with($path, $prefix) || $path === $basePath) {
                $uri = ($path === $basePath) ? '' : substr($path, strlen($prefix));
                $uri = trim($uri, '/');

                return [
                    'uri' => $uri ?: '__home__',
                    'siteId' => $entry['site']->id,
                ];
            }
        }

        // No prefix matched: the primary site, unless the URL is on another site's host;
        // then the site on that host without a base path.
        $site = $this->getPrimarySite();
        $candidateIds = array_map(fn(Site $candidate) => $candidate->id, $sites);

        if (!in_array($site->id, $candidateIds, true)) {
            foreach ($sitePaths as $entry) {
                if ($entry['basePath'] === '') {
                    $site = $entry['site'];
                    break;
                }
            }
        }

        return [
            'uri' => $path ?: '__home__',
            'siteId' => $site->id,
        ];
    }

    /**
     * Lower-cased host without port and without a leading `www.`, so `https://www.x.de/`
     * and a base URL of `https://x.de/` are the same host.
     */
    private static function host(string $authority): string
    {
        return preg_replace(['#:\d+$#', '#^www\.#'], '', strtolower($authority)) ?? $authority;
    }

    /**
     * @return Site[]
     */
    protected function getSites(): array
    {
        return $this->sites ?? Craft::$app->getSites()->getAllSites();
    }

    protected function getPrimarySite(): Site
    {
        if ($this->sites === null) {
            return Craft::$app->getSites()->getPrimarySite();
        }

        foreach ($this->sites as $site) {
            if ($site->primary) {
                return $site;
            }
        }

        return $this->sites[0];
    }
}
