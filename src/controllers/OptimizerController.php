<?php

namespace lameco\rankroute\controllers;

use Craft;
use craft\web\Controller;
use lameco\rankroute\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * `rankroute/optimizer/*`, also reachable as the legacy `_craft-entry-optimizer/optimized-entry`
 * (ADR 0002).
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
                'textExport' => 'rankroute/text/export',
                'textImport' => 'rankroute/text/import',
                'textVerify' => 'rankroute/text/verify',
                'textCreate' => 'rankroute/text/create',
            ],
        ]);
    }

    /**
     * Accepts `id` or `slug`. Any element type with a field layout is supported: entries,
     * Commerce products, categories. `slug` can carry a locale path prefix
     * (e.g. `nl/projecten`), resolved to a site through {@see \lameco\rankroute\services\ElementResolver}.
     * Response is always a one-element array (n8n contract, CONTEXT.md — Boundaries).
     */
    public function actionExport(): Response
    {
        $id = $this->request->getQueryParam('id');
        $slug = $this->request->getQueryParam('slug');

        if (!$id && !$slug) {
            throw new BadRequestHttpException('An element ID or slug is required.');
        }

        $exportService = Plugin::getInstance()->exportService;

        try {
            if ($id) {
                $exportResult = $exportService->exportById((int)$id);
            } else {
                ['uri' => $uri, 'siteId' => $siteId] = Plugin::getInstance()->elementResolver->resolve($slug);
                $exportResult = $exportService->exportBySlug($uri, $siteId);
            }

            return $this->asJson([$exportResult->toArray()]);
        } catch (NotFoundHttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            Craft::error('Export failed: ' . $e->getMessage(), __METHOD__);
            throw new BadRequestHttpException('Failed to export element: ' . $e->getMessage());
        }
    }

    /**
     * Accepts the raw JSON body as an import document. Only changed fields are written to
     * a draft; no changed fields, no draft (CONTEXT.md — Changed field).
     *
     * The session-notice branches craft-entry-optimizer 1.0.7 had here are gone: ADR 0001
     * removed session auth, so they were dead.
     */
    public function actionImport(): Response
    {
        $json = $this->request->getRawBody();

        if (empty($json)) {
            throw new BadRequestHttpException('No JSON data provided in request body.');
        }

        $importService = Plugin::getInstance()->importService;

        try {
            $importResult = $importService->importFromJson($json);

            if (!$importResult->success) {
                return $this->asJson([
                    'success' => false,
                    'message' => $importResult->message,
                    'errors' => $importResult->errors,
                ]);
            }

            if (empty($importResult->updatedFields)) {
                return $this->asJson([
                    'success' => true,
                    'message' => $importResult->message,
                    'entryId' => $importResult->entryId,
                    'updatedFields' => [],
                ]);
            }

            return $this->asJson([
                'success' => true,
                'message' => $importResult->message,
                'draftId' => $importResult->draftId,
                'entryId' => $importResult->entryId,
                'cpEditUrl' => $importResult->cpEditUrl,
                'updatedFields' => $importResult->updatedFields,
            ]);
        } catch (BadRequestHttpException $e) {
            throw $e;
        } catch (NotFoundHttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            Craft::error('Import failed: ' . $e->getMessage(), __METHOD__);
            throw new BadRequestHttpException('Failed to import element: ' . $e->getMessage());
        }
    }
}
