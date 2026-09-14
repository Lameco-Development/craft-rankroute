<?php

namespace lameco\rankroute\tests\integration;

use Craft;

final class PluginInstallTest extends IntegrationTestCase
{
    public function testPluginIsInstalledAndEnabled(): void
    {
        self::assertTrue(Craft::$app->getPlugins()->isPluginEnabled('rankroute'));
        self::assertSame('rankroute', $this->plugin()->id);
    }
}
