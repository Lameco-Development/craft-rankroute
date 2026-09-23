<?php

namespace lameco\rankroute\controllers;

use craft\helpers\App;
use yii\web\UnauthorizedHttpException;

/**
 * The Bearer-token check shared by every RankRoute action except `status` (ADR 0001).
 * Deliberately blind to the Craft session: a logged-in CP user without the header is
 * still unauthenticated here — "no CP-session fallback".
 */
trait ApiKeyAuthTrait
{
    /**
     * @throws UnauthorizedHttpException if the key is not configured, or the request's
     *     key does not match it
     */
    protected function requireApiKey(): void
    {
        $configuredKey = App::env('RANKROUTE_API_KEY');

        if (!is_string($configuredKey) || $configuredKey === '') {
            throw new UnauthorizedHttpException('API key not configured');
        }

        $header = $this->request->getHeaders()->get('Authorization') ?? '';

        if (!str_starts_with($header, 'Bearer ') || !hash_equals($configuredKey, substr($header, 7))) {
            throw new UnauthorizedHttpException('Authentication required');
        }
    }
}
