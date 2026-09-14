<?php

namespace lameco\rankroute\tests\integration;

use Craft;
use lameco\rankroute\Plugin;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use yii\db\Transaction;
use yii\web\Response;

/**
 * A test against the booted Craft app: fresh application per test, every database change
 * rolled back afterwards.
 */
abstract class IntegrationTestCase extends TestCase
{
    private ?Transaction $transaction = null;

    protected function setUp(): void
    {
        CraftHarness::ensureInstalled();
        CraftHarness::freshApp();

        $this->transaction = Craft::$app->getDb()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->transaction?->rollBack();
        $this->transaction = null;
        CraftHarness::teardownApp();
        $this->setApiKey(null);
    }

    protected function plugin(): Plugin
    {
        return CraftHarness::plugin();
    }

    /**
     * Sets or clears `RANKROUTE_API_KEY`. Cleared again in {@see tearDown()} so one test's
     * key never leaks into the next.
     */
    protected function setApiKey(?string $key): void
    {
        if ($key === null) {
            CraftHarness::unsetEnv('RANKROUTE_API_KEY');

            return;
        }

        CraftHarness::setEnv('RANKROUTE_API_KEY', $key);
    }

    /**
     * Dispatches a route through the booted app the way an HTTP request would, per the
     * Yii module/controllerMap resolution ADR 0002 depends on. Craft's own
     * {@see \craft\web\Controller} needs a real web request, hence {@see CraftHarness::useWebRequest()}.
     *
     * @param array<string, mixed> $queryParams `$_GET`-equivalent for actions that read query params (e.g. `export`).
     * @param string|null $rawBody The raw POST body for actions that read it directly (e.g. `import`).
     */
    protected function runAction(
        string $route,
        ?string $authorization = null,
        array $queryParams = [],
        ?string $rawBody = null,
    ): Response {
        CraftHarness::useWebRequest();

        // Craft only registers a plugin as a Yii module (ADR 0002) once it has actually
        // been loaded; force that now, or createController() finds neither the plugin's
        // own handle nor the legacy module ids `Plugin::init()` registers.
        $this->plugin();

        $request = Craft::$app->getRequest();
        $headers = $request->getHeaders();

        if ($authorization !== null) {
            $headers->set('Authorization', $authorization);
        }

        if ($queryParams !== []) {
            $request->setQueryParams($queryParams);
        }

        if ($rawBody !== null) {
            $request->setRawBody($rawBody);
        }

        $result = Craft::$app->runAction($route);

        if (!$result instanceof Response) {
            throw new RuntimeException(sprintf('Action "%s" did not return a web response.', $route));
        }

        return $result;
    }
}
