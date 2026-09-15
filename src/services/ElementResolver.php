<?php

namespace lameco\rankroute\services;

use Craft;
use craft\base\Component;
use craft\models\Site;

/**
 * Resolves a URL or path to a site and element URI by matching against each site's
 * base-URL path, longest prefix first. Extracted, unchanged, from
 * craft-entry-optimizer 1.0.7's `OptimizedEntryController::resolveSlug()`, so both the
 * optimizer export flow and the bulk meta flow (CONTEXT.md: Resolution) can share it.
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
        // Strip any leading/trailing slashes and any protocol/domain if present
        $path = preg_replace('#^https?://[^/]+/?#', '', $urlOrPath);
        $path = trim($path, '/');

        $sites = $this->getSites();

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

        // No prefix matched — use primary site
        $primarySite = $this->getPrimarySite();

        return [
            'uri' => $path ?: '__home__',
            'siteId' => $primarySite->id,
        ];
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
