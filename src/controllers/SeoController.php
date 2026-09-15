<?php

namespace lameco\rankroute\controllers;

use craft\web\Controller;
use lameco\rankroute\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * `rankroute/seo/import`, also reachable as the legacy `_craft-seo-import/api/import`
 * (ADR 0002). Bulk-writes SEOmatic meta title/description onto the live element,
 * addressed by URL (CONTEXT.md — Bulk meta flow); nothing is drafted or reviewed.
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

    /**
     * Accepts the raw JSON body as bulk meta items (CONTEXT.md — Bulk meta item), writes
     * SEOmatic's `seoTitle`/`seoDescription` onto the live element found for each `url`.
     */
    public function actionImport(): Response
    {
        $this->requirePostRequest();

        $json = $this->request->getRawBody();

        $seoBulkService = Plugin::getInstance()->seoBulkService;

        $items = $seoBulkService->normalizeItems($json);

        if (!$seoBulkService->seomaticInstalled()) {
            throw new BadRequestHttpException('SEOmatic is not installed');
        }

        $result = $seoBulkService->import($items);

        return $this->asJson($result->toArray());
    }
}
