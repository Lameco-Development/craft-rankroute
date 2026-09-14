<?php

namespace lameco\rankroute\controllers;

use craft\web\Controller;
use yii\web\Response;

/**
 * `rankroute/seo/import`, also reachable as the legacy `_craft-seo-import/api/import`
 * (ADR 0002). Bulk meta write lands in issue #3; here it only answers 501.
 */
class SeoController extends Controller
{
    use ApiKeyAuthTrait;

    public $enableCsrfValidation = false;

    /**
     * Craft's anonymous gate is bypassed because auth is the shared Bearer check in
     * beforeAction() (ADR 0001), not a user session.
     *
     * @var array<string, int>
     */
    protected array|bool|int $allowAnonymous = [
        'import' => self::ALLOW_ANONYMOUS_LIVE,
    ];

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireApiKey();

        return true;
    }

    public function actionImport(): Response
    {
        return $this->notImplemented();
    }
}
