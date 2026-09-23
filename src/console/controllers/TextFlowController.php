<?php

namespace lameco\rankroute\console\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\console\Controller;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\models\Site;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use lameco\rankroute\Plugin;
use lameco\rankroute\services\text\SmokeRewrite;
use Throwable;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Smoke-tests the text flow against a running site, over HTTP, the way n8n uses it.
 */
class TextFlowController extends Controller
{
    public $defaultAction = 'smoke';

    /**
     * @var string|null Site handle to test; all sites when omitted.
     */
    public ?string $site = null;

    /**
     * @var string|null Test only the element with this URI (e.g. `solutions/applications`).
     */
    public ?string $uri = null;

    /**
     * @var int|null Maximum number of elements to test.
     */
    public ?int $limit = null;

    /**
     * @var bool Keep every draft instead of deleting it after the checks.
     */
    public bool $keepDrafts = false;

    /**
     * @var int Run the negative probes (changed href, missing id, stale fingerprint) on the first n elements with text.
     */
    public int $probe = 3;

    /**
     * @var int Keep the drafts of n random elements and print their preview URLs.
     */
    public int $sample = 0;

    /**
     * @var string|null Base URL for the HTTP calls; defaults to each element's site base URL.
     */
    public ?string $baseUrl = null;

    /**
     * @var bool Print response bodies of failures and structure differences.
     */
    public bool $verbose = false;

    /**
     * @var callable|null Guzzle handler to send requests through instead of the network.
     * Not a command option: a seam for tests.
     * @internal
     */
    public $httpHandler = null;

    private ?Client $client = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'smoke') {
            array_push($options, 'site', 'uri', 'limit', 'keepDrafts', 'probe', 'sample', 'baseUrl', 'verbose');
        }

        return $options;
    }

    public function optionAliases(): array
    {
        return [
            ...parent::optionAliases(),
            's' => 'site',
            'u' => 'uri',
            'l' => 'limit',
            'k' => 'keepDrafts',
            'p' => 'probe',
            'b' => 'baseUrl',
            'v' => 'verbose',
        ];
    }

    /**
     * For every live element with a URI: export, deterministic rewrite, import, structure
     * check, verify, negative probes, draft cleanup. Exits non-zero on any failure;
     * elements without text items are skipped.
     */
    public function actionSmoke(): int
    {
        $apiKey = App::env('RANKROUTE_API_KEY');

        if (!is_string($apiKey) || $apiKey === '') {
            $this->stderr("RANKROUTE_API_KEY is not set.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        try {
            $elements = $this->elements();
        } catch (Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        if ($elements === []) {
            $this->stdout("No live elements with a URI found.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $sampled = $this->sample > 0
            ? array_flip((array)array_rand($elements, min($this->sample, count($elements))))
            : [];

        $rows = [];
        $previews = [];
        $counts = ['passed' => 0, 'skipped' => 0, 'failed' => 0];
        $probesLeft = $this->probe;

        foreach ($elements as $index => $element) {
            $keep = $this->keepDrafts || isset($sampled[$index]);
            $result = $this->smokeElement($element, $apiKey, $probesLeft > 0, $keep);

            if ($result['probed']) {
                --$probesLeft;
            }

            ++$counts[$result['status']];
            $rows[] = [
                $element->getSite()->handle,
                $element->uri,
                (string)$result['items'],
                $result['draftId'] !== null ? (string)$result['draftId'] : '-',
                $result['checks'],
                strtoupper($result['status']),
                $result['note'],
            ];

            if ($result['status'] === 'failed') {
                $this->stdout(sprintf("FAIL %s: %s\n", $element->uri, $result['note']), Console::FG_RED);

                if ($this->verbose && $result['details'] !== null) {
                    $this->stdout($result['details'] . "\n");
                }
            } elseif ($this->verbose) {
                $this->stdout(sprintf("%s %s\n", strtoupper($result['status']), $element->uri));
            }

            if (isset($sampled[$index]) && $result['draftId'] !== null) {
                $previews[] = [$element->uri, $this->previewUrl($element, $result['draftId']), $result['cpEditUrl'] ?? ''];
            }
        }

        $this->stdout("\n");
        $this->table(['Site', 'URI', 'Items', 'Draft', 'Checks', 'Result', 'Note'], $rows);

        if ($previews !== []) {
            $this->stdout("\nSampled drafts (kept):\n");
            $this->table(['URI', 'Preview', 'Edit'], $previews);
        }

        $summary = sprintf(
            "\n%d elements: %d passed, %d skipped, %d failed.\n",
            count($elements),
            $counts['passed'],
            $counts['skipped'],
            $counts['failed'],
        );
        $this->stdout($summary, $counts['failed'] > 0 ? Console::FG_RED : Console::FG_GREEN);

        return $counts['failed'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * @return array{status: string, items: int, draftId: int|null, cpEditUrl: string|null, checks: string, note: string, probed: bool, details: string|null}
     */
    private function smokeElement(ElementInterface $element, string $apiKey, bool $probe, bool $keep): array
    {
        $result = ['status' => 'failed', 'items' => 0, 'draftId' => null, 'cpEditUrl' => null, 'checks' => '', 'note' => '', 'probed' => false, 'details' => null];
        $checks = [];
        $siteId = (int)$element->siteId;

        try {
            $base = $this->baseUrlFor($element->getSite());

            [$status, $export] = $this->request('GET', $base, 'export', $apiKey, query: ['id' => $element->id, 'siteId' => $siteId]);

            if ($status !== 200 || !isset($export['items'], $export['fingerprint'])) {
                return [...$result, 'note' => "export answered {$status}", 'details' => json_encode($export)];
            }

            // A base URL pointing at another installation would import there, and the
            // cleanup here would then act on this installation's drafts.
            if (($export['element']['id'] ?? null) !== (int)$element->id || ($export['element']['siteId'] ?? null) !== $siteId) {
                return [...$result, 'note' => sprintf(
                    'export returned element %s in site %s, expected %d in site %d (does the base URL point at this site?)',
                    json_encode($export['element']['id'] ?? null),
                    json_encode($export['element']['siteId'] ?? null),
                    $element->id,
                    $siteId,
                )];
            }

            $items = $export['items'];
            $result['items'] = count($items);
            $checks[] = 'export';

            if ($items === []) {
                return [...$result, 'status' => 'skipped', 'checks' => 'export', 'note' => 'no text items'];
            }

            $rewritten = array_map(fn(array $item) => [
                'id' => $item['id'],
                'value' => $item['type'] === 'html' ? SmokeRewrite::html($item['value']) : SmokeRewrite::plain($item['value'], $item['maxLength']),
            ], $items);
            $idempotencyKey = sprintf('smoke-%d-%d-%s', $element->id, $siteId, bin2hex(random_bytes(4)));
            $payload = ['elementId' => $element->id, 'siteId' => $siteId, 'fingerprint' => $export['fingerprint'], 'items' => $rewritten, 'idempotencyKey' => $idempotencyKey];

            try {
                [$status, $import] = $this->request('POST', $base, 'import', $apiKey, body: $payload);
            } catch (Throwable $e) {
                // The import may have created its draft before the connection failed.
                $this->deleteDraftByIdempotencyKey($element, $idempotencyKey, $keep);
                throw $e;
            }

            if ($status !== 200 || empty($import['success'])) {
                $this->deleteDraftByIdempotencyKey($element, $idempotencyKey, $keep);

                return [...$result, 'checks' => implode(',', $checks), 'note' => "import answered {$status}", 'details' => json_encode($import)];
            }

            $result['draftId'] = $import['draftId'] ?? null;
            $result['cpEditUrl'] = $import['cpEditUrl'] ?? null;
            $checks[] = 'import';

            try {
                if (empty($import['structureCheck']['passed'])) {
                    return [...$result, 'checks' => implode(',', $checks), 'note' => 'structure check failed', 'details' => json_encode($import['structureCheck'] ?? null)];
                }

                if (count($import['changedItems'] ?? []) !== count($items)) {
                    return [...$result, 'checks' => implode(',', $checks), 'note' => sprintf('%d of %d items changed', count($import['changedItems'] ?? []), count($items))];
                }

                $checks[] = 'structure';

                [$status, $verify] = $this->request('GET', $base, 'verify', $apiKey, query: ['draftId' => $result['draftId'], 'siteId' => $siteId]);

                if ($status !== 200 || empty($verify['structureCheck']['passed'])) {
                    return [...$result, 'checks' => implode(',', $checks), 'note' => "verify answered {$status}", 'details' => json_encode($verify)];
                }

                $checks[] = 'verify';

                if ($probe) {
                    $result['probed'] = true;
                    $probeFailure = $this->probes($base, $apiKey, $payload, $items);

                    if ($probeFailure !== null) {
                        return [...$result, 'checks' => implode(',', $checks), 'note' => $probeFailure];
                    }

                    // A retry with the same idempotency key answers from the same draft.
                    [$status, $replay] = $this->request('POST', $base, 'import', $apiKey, body: $payload);

                    if ($status !== 200 || ($replay['replayed'] ?? null) !== true || ($replay['draftId'] ?? null) !== $result['draftId']) {
                        return [...$result, 'checks' => implode(',', $checks), 'note' => "probe \"replay\" answered {$status}", 'details' => json_encode($replay)];
                    }

                    $checks[] = 'probes';
                }

                return [...$result, 'status' => 'passed', 'checks' => implode(',', $checks), 'note' => $keep ? 'draft kept' : ''];
            } finally {
                if (!$keep && $result['draftId'] !== null) {
                    $this->deleteDraft($element, (int)$result['draftId']);
                }
            }
        } catch (Throwable $e) {
            return [...$result, 'checks' => implode(',', $checks), 'note' => get_class($e) . ': ' . $e->getMessage()];
        }
    }

    /**
     * The negative probes; each must be refused without writing anything.
     *
     * @param array<string, mixed> $payload A valid import payload
     * @param list<array<string, mixed>> $items The exported items
     * @return string|null What went wrong, or null when every probe was refused as expected
     */
    private function probes(string $base, string $apiKey, array $payload, array $items): ?string
    {
        // No idempotency key on probes: with the successful import's key they would be
        // answered as a replay of it.
        unset($payload['idempotencyKey']);
        $probes = [];

        foreach ($items as $index => $item) {
            if ($item['type'] === 'html' && ($changed = SmokeRewrite::withChangedHref($item['value'])) !== null) {
                $hrefPayload = $payload;
                $hrefPayload['items'][$index]['value'] = $changed;
                $probes['changed href'] = [$hrefPayload, 422, 'html_structure_changed'];
                break;
            }
        }

        $missingPayload = $payload;
        array_pop($missingPayload['items']);
        $probes['missing id'] = [$missingPayload, 422, 'missing_id'];
        $probes['stale fingerprint'] = [[...$payload, 'fingerprint' => 'sha256:' . str_repeat('0', 64)], 409, 'fingerprint_mismatch'];

        foreach ($probes as $name => [$probePayload, $expectedStatus, $expectedCode]) {
            [$status, $body] = $this->request('POST', $base, 'import', $apiKey, body: $probePayload);
            $codes = array_column($body['errors'] ?? [], 'code');

            if ($status !== $expectedStatus || !in_array($expectedCode, $codes, true)) {
                return "probe \"{$name}\" answered {$status} " . implode(',', $codes);
            }
        }

        return null;
    }

    /**
     * @return ElementInterface[]
     */
    private function elements(): array
    {
        $sites = Craft::$app->getSites();

        if ($this->site !== null) {
            $site = $sites->getSiteByHandle($this->site);

            if (!$site) {
                throw new \InvalidArgumentException("Unknown site \"{$this->site}\".");
            }

            $siteList = [$site];
        } else {
            $siteList = $sites->getAllSites();
        }

        $elements = [];

        foreach ($siteList as $site) {
            if ($this->uri !== null) {
                $element = Craft::$app->getElements()->getElementByUri(trim($this->uri, '/') ?: '__home__', $site->id, true);

                if ($element) {
                    $elements[] = $element;
                }

                continue;
            }

            /** @var list<class-string<ElementInterface>> $types */
            $types = [Entry::class, Category::class];

            if (class_exists('craft\commerce\elements\Product')) {
                $types[] = 'craft\commerce\elements\Product';
            }

            foreach ($types as $type) {
                $query = $type::find()->siteId($site->id)->status('live')->uri(':notempty:')->orderBy(['elements.id' => SORT_ASC]);

                foreach ($query->all() as $element) {
                    if ($element instanceof Entry && $element->fieldId !== null) {
                        continue;
                    }

                    $elements[] = $element;
                }
            }
        }

        return $this->limit !== null ? array_slice($elements, 0, $this->limit) : $elements;
    }

    private function baseUrlFor(Site $site): string
    {
        $base = $this->baseUrl ?? $site->getBaseUrl();

        if (!$base || !preg_match('#^https?://#', $base)) {
            throw new \RuntimeException("Site \"{$site->handle}\" has no absolute base URL; pass --base-url.");
        }

        return rtrim($base, '/');
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: mixed}
     */
    private function request(string $method, string $base, string $action, string $apiKey, array $query = [], ?array $body = null): array
    {
        if ($this->client === null) {
            $config = ['http_errors' => false, 'timeout' => 120];

            if ($this->httpHandler !== null) {
                $config['handler'] = HandlerStack::create($this->httpHandler);
            }

            $this->client = Craft::createGuzzleClient($config);
        }

        $options = [
            'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'],
            'query' => $query,
        ];

        if ($body !== null) {
            $options['body'] = json_encode($body);
            $options['headers']['Content-Type'] = 'application/json';
        }

        $response = $this->client->request($method, "{$base}/actions/rankroute/text/{$action}", $options);
        $contents = (string)$response->getBody();
        $decoded = json_decode($contents, true);

        return [$response->getStatusCode(), json_last_error() === JSON_ERROR_NONE ? $decoded : mb_substr($contents, 0, 500)];
    }

    /**
     * Hard-deletes a draft, but only a draft of this very element: the draft id comes from
     * an HTTP response and must never be able to point at another element's draft.
     */
    private function deleteDraft(ElementInterface $element, int $draftId): void
    {
        $draft = $element::find()
            ->draftId($draftId)
            ->draftOf($element->id)
            ->siteId($element->siteId)
            ->status(null)
            ->one();

        if ($draft && $draft->getCanonicalId() === (int)$element->id) {
            Craft::$app->getElements()->deleteElement($draft, true);
        }
    }

    private function deleteDraftByIdempotencyKey(ElementInterface $element, string $key, bool $keep): void
    {
        if ($keep) {
            return;
        }

        $found = Plugin::getInstance()->textImportService->findDraftByIdempotencyKey($element, $key);

        if ($found !== null) {
            $this->deleteDraft($element, (int)$found['draft']->draftId);
        }
    }

    private function previewUrl(ElementInterface $element, int $draftId): string
    {
        $token = Craft::$app->getTokens()->createPreviewToken([
            'preview/preview', [
                'elementType' => get_class($element),
                'canonicalId' => (int)$element->id,
                'siteId' => (int)$element->siteId,
                'draftId' => $draftId,
                'revisionId' => null,
                'userId' => null,
            ],
        ]);

        $url = $element->getUrl();

        return $token && $url ? UrlHelper::urlWithToken($url, $token) : '';
    }
}
