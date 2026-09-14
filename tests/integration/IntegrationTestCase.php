<?php

namespace lameco\rankroute\tests\integration;

use Craft;
use lameco\rankroute\Plugin;
use PHPUnit\Framework\TestCase;
use yii\db\Transaction;

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
    }

    protected function plugin(): Plugin
    {
        return CraftHarness::plugin();
    }
}
