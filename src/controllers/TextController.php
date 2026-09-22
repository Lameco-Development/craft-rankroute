<?php

namespace lameco\rankroute\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\web\Controller;
use lameco\rankroute\Plugin;
use Throwable;
use yii\web\HttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * `rankroute/text/*`: the text flow (export, import, create, verify). Errors other than
 * import/create validation answer `{"error": "…"}` with their HTTP status; validation and
 * structure check failures answer their own documented body.
 */
class TextController extends Controller
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
        'export' => self::ALLOW_ANONYMOUS_LIVE,
        'import' => self::ALLOW_ANONYMOUS_LIVE,
        'create' => self::ALLOW_ANONYMOUS_LIVE,
        'verify' => self::ALLOW_ANONYMOUS_LIVE,
    ];

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        try {
            $this->requireApiKey();
        } catch (UnauthorizedHttpException $e) {
            // Answered here rather than thrown, so the body is the text flow's error shape.
            $this->errorResponse($e);

            return false;
        }

        return true;
    }

    /**
     * `GET ?url=<url or path>` or `GET ?id=<elementId>&siteId=<id>`.
     */
    public function actionExport(): Response
    {
        try {
            $plugin = Plugin::getInstance();
            $element = $plugin->textExportService->findElement(
                $this->request->getQueryParam('url'),
                $this->request->getQueryParam('id'),
                $this->request->getQueryParam('siteId'),
            );

            return $this->asJson($plugin->textExportService->export($element)->toArray());
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * `POST {elementId, siteId, fingerprint, items: [{id, value}]}` (or `url` instead of
     * `elementId`/`siteId`).
     */
    public function actionImport(): Response
    {
        try {
            $result = Plugin::getInstance()->textImportService->import($this->jsonBody());
            $this->response->setStatusCode($result->statusCode);

            return $this->asJson($result->toArray());
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * `POST {sourceElementId, siteId, fingerprint, slug, items: [{id, value}], idempotencyKey?}`
     * (or `url` instead of `sourceElementId`/`siteId`): a new page as an unpublished draft,
     * copied from the source (ADR 0004).
     */
    public function actionCreate(): Response
    {
        try {
            $result = Plugin::getInstance()->textCreateService->create($this->jsonBody());
            $this->response->setStatusCode($result->statusCode);

            return $this->asJson($result->toArray());
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * `GET ?draftId=<drafts.id>[&siteId=<id>]`: the structure check for an existing draft,
     * against its canonical element, or for a new page from `text/create`, against its
     * source. Without `siteId` a draft is checked in the primary site, or in the first site
     * it exists in; a new page in the site it was created for.
     */
    public function actionVerify(): Response
    {
        try {
            $draftId = $this->request->getQueryParam('draftId');
            $siteId = $this->request->getQueryParam('siteId');

            if (!is_numeric($draftId)) {
                throw new HttpException(400, 'A draftId is required.');
            }

            $elementType = (new Query())
                ->select(['type'])
                ->from(Table::ELEMENTS)
                ->where(['draftId' => (int)$draftId])
                ->scalar();

            if (!is_string($elementType) || !is_subclass_of($elementType, ElementInterface::class)) {
                throw new HttpException(404, "Draft {$draftId} not found.");
            }

            /** @var class-string<ElementInterface> $elementType */
            $query = $elementType::find()->draftId((int)$draftId)->status(null);

            if (is_numeric($siteId)) {
                $query->siteId((int)$siteId);
            } else {
                $query->site('*')->unique()->preferSites([Craft::$app->getSites()->getPrimarySite()->id]);
            }

            $draft = $query->one();

            if ($draft !== null && $draft->getIsUnpublishedDraft()) {
                return $this->verifyNewPage($draft, (int)$draftId, is_numeric($siteId));
            }

            $canonical = $draft ? Craft::$app->getElements()->getElementById($draft->getCanonicalId(), $elementType, $draft->siteId) : null;

            if (!$draft || !$canonical) {
                throw new HttpException(404, "Draft {$draftId} not found.");
            }

            $check = Plugin::getInstance()->structureCheck->check($canonical, $draft);

            return $this->asJson([
                'success' => true,
                'elementId' => (int)$canonical->id,
                'siteId' => (int)$draft->siteId,
                'draftId' => (int)$draftId,
                'draftElementId' => (int)$draft->id,
                'structureCheck' => $check->toArray(),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * @throws HttpException 404 when the unpublished draft is not a new page from `text/create`
     */
    private function verifyNewPage(ElementInterface $draft, int $draftId, bool $siteGiven): Response
    {
        $verified = Plugin::getInstance()->textCreateService->verify($draft, $siteGiven)
            ?? throw new HttpException(404, "Draft {$draftId} is not a new page from text/create, or its source no longer exists.");
        $draft = $verified['draft'];

        return $this->asJson([
            'success' => true,
            'elementId' => (int)$draft->id,
            'sourceElementId' => (int)$verified['source']->id,
            'siteId' => (int)$draft->siteId,
            'draftId' => $draftId,
            'draftElementId' => (int)$draft->id,
            'structureCheck' => $verified['check']->toArray(),
        ]);
    }

    /**
     * @throws HttpException 400 for a missing or malformed body
     */
    private function jsonBody(): mixed
    {
        $this->requirePostRequest();
        $body = $this->request->getRawBody();
        $payload = json_decode($body, true);

        if ($body === '' || json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpException(400, 'The request body must be valid JSON.');
        }

        return $payload;
    }

    private function errorResponse(Throwable $e): Response
    {
        if ($e instanceof HttpException) {
            $status = $e->statusCode;
            $message = $e->getMessage();
        } else {
            // The detail can carry paths, SQL or field values: log it, answer only a
            // reference to find it by.
            $errorId = bin2hex(random_bytes(4));
            Craft::error("Text flow request failed [{$errorId}]: " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);
            $status = 500;
            $message = "Internal error (ref {$errorId}).";
        }

        $this->response->setStatusCode($status);

        return $this->asJson(['error' => $message]);
    }
}
