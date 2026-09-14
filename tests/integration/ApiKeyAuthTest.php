<?php

namespace lameco\rankroute\tests\integration;

use Craft;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\web\UnauthorizedHttpException;

/**
 * The Bearer-token check every RankRoute action but `status` requires (ADR 0001): no
 * `RANKROUTE_API_KEY` is 401, the wrong key is 401, and — because there is no CP-session
 * fallback — being logged into Craft doesn't buy an authenticated request either.
 *
 * The rejection cases run against every gate the plugin has — `OptimizerController` and
 * `SeoController` each call `requireApiKey()` from their own `beforeAction()`, so a
 * single route can pass while the other controller's check has been deleted entirely.
 */
final class ApiKeyAuthTest extends IntegrationTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function protectedRouteProvider(): array
    {
        return [
            'optimizer export' => ['rankroute/optimizer/export'],
            'seo import' => ['rankroute/seo/import'],
            'legacy seo import' => ['_craft-seo-import/api/import'],
        ];
    }

    #[DataProvider('protectedRouteProvider')]
    public function testProtectedActionRejectsWhenNoKeyIsConfigured(string $route): void
    {
        $this->setApiKey(null);

        try {
            $this->runAction($route, 'Bearer whatever');
            self::fail('Expected an UnauthorizedHttpException.');
        } catch (UnauthorizedHttpException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertSame('API key not configured', $e->getMessage());
        }
    }

    #[DataProvider('protectedRouteProvider')]
    public function testProtectedActionRejectsAWrongKey(string $route): void
    {
        $this->setApiKey('correct-key');

        try {
            $this->runAction($route, 'Bearer wrong-key');
            self::fail('Expected an UnauthorizedHttpException.');
        } catch (UnauthorizedHttpException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertSame('Authentication required', $e->getMessage());
        }
    }

    #[DataProvider('protectedRouteProvider')]
    public function testProtectedActionRejectsAMissingHeader(string $route): void
    {
        $this->setApiKey('correct-key');

        try {
            $this->runAction($route);
            self::fail('Expected an UnauthorizedHttpException.');
        } catch (UnauthorizedHttpException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertSame('Authentication required', $e->getMessage());
        }
    }

    /**
     * The right key gets past the gate: not implemented until issue #2, but that's a 501
     * from the action itself, not a 401 from the auth check.
     */
    public function testProtectedActionAcceptsTheCorrectKey(): void
    {
        $this->setApiKey('correct-key');

        $response = $this->runAction('rankroute/optimizer/export', 'Bearer correct-key');

        self::assertSame(501, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'Not implemented until 0.0.1'], $response->data);
    }

    #[DataProvider('protectedRouteProvider')]
    public function testALoggedInUserWithoutTheHeaderStillGets401(string $route): void
    {
        $this->setApiKey('correct-key');

        $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('tester');
        self::assertNotNull($admin, 'The install migration should have seeded the "tester" admin.');

        Craft::$app->getUser()->setIdentity($admin);

        try {
            $this->runAction($route);
            self::fail('Expected an UnauthorizedHttpException.');
        } catch (UnauthorizedHttpException $e) {
            self::assertSame('Authentication required', $e->getMessage());
        }
    }

    public function testStatusIsPublicWithNoKeyConfigured(): void
    {
        $this->setApiKey(null);

        $response = $this->runAction('rankroute/optimizer/status');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testStatusIsPublicEvenWithAKeyConfigured(): void
    {
        $this->setApiKey('correct-key');

        $response = $this->runAction('rankroute/optimizer/status');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('active', $response->data['status']);
    }
}
