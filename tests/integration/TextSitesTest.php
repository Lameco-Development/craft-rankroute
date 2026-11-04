<?php

namespace lameco\rankroute\tests\integration;

use Craft;

/**
 * `text/sites`: every site with URLs, with its language and base URL, behind the API key.
 */
final class TextSitesTest extends TextFlowFixtureTestCase
{
    protected array $extraSites = [
        'gb' => ['RankRoute GB', 'en-GB', 'https://rankroute.test/en-gb/'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTextFlowContent();
        $this->setApiKey(self::API_KEY);
    }

    public function testListsEverySiteWithItsLanguageAndBaseUrl(): void
    {
        $response = $this->textAction('sites');

        self::assertSame(200, $response->getStatusCode());
        $sites = array_column($response->data['sites'], null, 'handle');
        $primary = Craft::$app->getSites()->getPrimarySite();

        self::assertSame(['id', 'handle', 'name', 'language', 'baseUrl', 'primary', 'enabled'], array_keys($sites['nl']));
        self::assertSame($this->nlSiteId, $sites['nl']['id']);
        self::assertSame('nl-NL', $sites['nl']['language']);
        self::assertSame('https://rankroute.test/nl/', $sites['nl']['baseUrl']);
        self::assertFalse($sites['nl']['primary']);
        self::assertTrue($sites['nl']['enabled']);
        self::assertSame('en-GB', $sites['gb']['language']);
        self::assertSame('https://rankroute.test/en-gb/', $sites['gb']['baseUrl']);
        self::assertSame($primary->language, $sites[$primary->handle]['language']);
        self::assertTrue($sites[$primary->handle]['primary']);
    }

    public function testASiteWithoutUrlsIsLeftOut(): void
    {
        $site = Craft::$app->getSites()->getSiteById($this->extraSiteIds['gb']);
        $site->hasUrls = false;
        Craft::$app->getSites()->saveSite($site);

        $response = $this->textAction('sites');

        self::assertSame(200, $response->getStatusCode());
        self::assertNotContains('gb', array_column($response->data['sites'], 'handle'));
    }

    public function testRequiresTheApiKey(): void
    {
        $response = $this->textAction('sites', apiKey: 'wrong');

        self::assertSame(401, $response->getStatusCode());
    }
}
