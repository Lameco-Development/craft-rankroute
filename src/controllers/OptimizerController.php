<?php

namespace lameco\rankroute\controllers;

use craft\web\Controller;
use lameco\rankroute\Plugin;
use yii\web\Response;

/**
 * `rankroute/optimizer/*`, also reachable as the legacy `_craft-entry-optimizer/optimized-entry`
 * (ADR 0002). Export/import land in issue #2; here they only answer 501.
 */
class OptimizerController extends Controller
{
    use ApiKeyAuthTrait;

    public $defaultAction = 'status';
    public $enableCsrfValidation = false;

    /**
     * Craft's anonymous gate is bypassed because auth is the shared Bearer check in
     * beforeAction() (ADR 0001, no CP-session fallback), not a user session.
     *
     * @var array<string, int>
     */
    protected array|bool|int $allowAnonymous = [
        'status' => self::ALLOW_ANONYMOUS_LIVE,
        'export' => self::ALLOW_ANONYMOUS_LIVE,
        'import' => self::ALLOW_ANONYMOUS_LIVE,
    ];

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // `status` is the one public endpoint (ADR 0001); everything else needs the
        // shared API key, not a Craft session.
        if ($action->id !== 'status') {
            $this->requireApiKey();
        }

        return true;
    }

    public function actionStatus(): Response
    {
        return $this->asJson([
            'plugin' => 'RankRoute',
            'version' => Plugin::getInstance()->getVersion(),
            'status' => 'active',
            'endpoints' => [
                'export' => 'rankroute/optimizer/export',
                'import' => 'rankroute/optimizer/import',
                'seoImport' => 'rankroute/seo/import',
            ],
        ]);
    }

    public function actionExport(): Response
    {
        return $this->notImplemented();
    }

    public function actionImport(): Response
    {
        return $this->notImplemented();
    }
}
