<?php

namespace lameco\rankroute\tests\integration;

use Craft;
use lameco\rankroute\controllers\OptimizerController;
use lameco\rankroute\controllers\SeoController;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The spike ADR 0002 is conditional on: every new action path, and every legacy alias
 * from craft-entry-optimizer/craft-seo-import, has to dispatch to the intended controller
 * action through the booted app. The aliasing depends on Yii's module/controllerMap
 * resolution (`Plugin::init()`), not on anything this test could fake at a lower level.
 */
final class ActionDispatchTest extends IntegrationTestCase
{
    /**
     * @return array<string, array{0: string, 1: class-string, 2: string}>
     */
    public static function routeProvider(): array
    {
        return [
            'new optimizer status' => ['rankroute/optimizer/status', OptimizerController::class, 'status'],
            'new optimizer export' => ['rankroute/optimizer/export', OptimizerController::class, 'export'],
            'new optimizer import' => ['rankroute/optimizer/import', OptimizerController::class, 'import'],
            'new seo import' => ['rankroute/seo/import', SeoController::class, 'import'],
            'legacy optimized-entry (status)' => ['_craft-entry-optimizer/optimized-entry', OptimizerController::class, ''],
            'legacy optimized-entry/export' => ['_craft-entry-optimizer/optimized-entry/export', OptimizerController::class, 'export'],
            'legacy optimized-entry/import' => ['_craft-entry-optimizer/optimized-entry/import', OptimizerController::class, 'import'],
            'legacy api/import' => ['_craft-seo-import/api/import', SeoController::class, 'import'],
        ];
    }

    #[DataProvider('routeProvider')]
    public function testRouteResolvesToTheIntendedControllerAction(
        string $route,
        string $expectedControllerClass,
        string $expectedActionId,
    ): void {
        // A web request loads the plugin, which registers it as a Yii module under its own
        // handle and the two legacy ids (ADR 0002); createController() needs that done first.
        CraftHarness::useWebRequest();

        $resolved = Craft::$app->createController($route);

        self::assertNotFalse($resolved, "\"{$route}\" did not resolve to a controller.");

        [$controller, $actionId] = $resolved;

        self::assertInstanceOf($expectedControllerClass, $controller);
        self::assertSame($expectedActionId, $actionId);
    }

    public function testStatusIsReachableOnTheNewPathWithoutAKey(): void
    {
        $response = $this->runAction('rankroute/optimizer/status');

        self::assertSame(200, $response->getStatusCode());
        self::assertStatusPayload($response->data);
    }

    public function testStatusIsReachableOnTheLegacyPathWithoutAKey(): void
    {
        // '' as the action id (see routeProvider) is $defaultAction taking over — the
        // index route is the legacy health check (ADR 0002).
        $response = $this->runAction('_craft-entry-optimizer/optimized-entry');

        self::assertSame(200, $response->getStatusCode());
        self::assertStatusPayload($response->data);
    }

    /**
     * The status body is a contract with n8n: every key, and the advertised paths.
     */
    private static function assertStatusPayload(mixed $data): void
    {
        self::assertSame([
            'plugin' => 'RankRoute',
            'version' => CraftHarness::plugin()->getVersion(),
            'status' => 'active',
            'endpoints' => [
                'export' => 'rankroute/optimizer/export',
                'import' => 'rankroute/optimizer/import',
                'seoImport' => 'rankroute/seo/import',
            ],
        ], $data);
    }

    public function testLegacyExportDispatchesThroughToOptimizerExport(): void
    {
        $this->setApiKey('correct-key');

        $response = $this->runAction('_craft-entry-optimizer/optimized-entry/export', 'Bearer correct-key');

        self::assertSame(501, $response->getStatusCode());
    }

    public function testLegacyImportDispatchesThroughToOptimizerImport(): void
    {
        $this->setApiKey('correct-key');

        $response = $this->runAction('_craft-entry-optimizer/optimized-entry/import', 'Bearer correct-key');

        self::assertSame(501, $response->getStatusCode());
    }

    public function testLegacySeoImportDispatchesThroughToSeoImport(): void
    {
        $this->setApiKey('correct-key');

        $response = $this->runAction('_craft-seo-import/api/import', 'Bearer correct-key');

        self::assertSame(501, $response->getStatusCode());
    }

    public function testNewSeoImportDispatchesThroughToSeoImport(): void
    {
        $this->setApiKey('correct-key');

        $response = $this->runAction('rankroute/seo/import', 'Bearer correct-key');

        self::assertSame(501, $response->getStatusCode());
    }
}
