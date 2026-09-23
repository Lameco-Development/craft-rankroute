<?php

namespace lameco\rankroute\tests\integration;

use Craft;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use lameco\rankroute\console\controllers\TextFlowController;
use Psr\Http\Message\RequestInterface;
use yii\console\ExitCode;

/**
 * `rankroute/text-flow/smoke` with its HTTP calls dispatched into the booted app instead
 * of the network, so the command's own logic (rewrite, checks, probes, cleanup) is proven
 * against the real endpoints.
 */
final class TextFlowSmokeCommandTest extends TextFlowFixtureTestCase
{
    /** @var list<string> */
    private array $requests = [];

    private ?int $draftsBeforeTransportError = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTextFlowContent();
        $this->setApiKey(self::API_KEY);
    }

    public function testTheCommandRouteResolvesInTheConsoleApp(): void
    {
        $this->plugin();

        $resolved = Craft::$app->createController('rankroute/text-flow/smoke');

        self::assertNotFalse($resolved);
        self::assertInstanceOf(TextFlowController::class, $resolved[0]);
        self::assertSame('smoke', $resolved[1]);
    }

    public function testSmokePassesForTheFixturePageAndDeletesItsDraft(): void
    {
        $command = $this->command();
        $command->uri = $this->pageUri;
        $command->site = 'default';

        $exitCode = $command->actionSmoke();

        self::assertSame(ExitCode::OK, $exitCode, $command->output);
        self::assertStringContainsString('1 elements: 1 passed, 0 skipped, 0 failed.', $command->output);
        self::assertStringContainsString('export,import,structure,verify,probes', $command->output);
        self::assertSame(0, $this->draftCount());

        // export, import, verify, the three probes, then the replay.
        self::assertSame(['GET export', 'POST import', 'GET verify', 'POST import', 'POST import', 'POST import', 'POST import'], $this->requests);
    }

    public function testKeepDraftsKeepsTheDraft(): void
    {
        $command = $this->command();
        $command->uri = $this->pageUri;
        $command->site = 'nl';
        $command->keepDrafts = true;
        $command->probe = 0;

        self::assertSame(ExitCode::OK, $command->actionSmoke(), $command->output);
        self::assertSame(1, $this->draftCount());
        self::assertSame(['GET export', 'POST import', 'GET verify'], $this->requests);
    }

    public function testAllSitesAreSmokedAndAFailureMakesTheExitCodeNonZero(): void
    {
        $command = $this->command(failImports: true);
        $command->limit = 2;
        $command->probe = 0;

        self::assertSame(ExitCode::UNSPECIFIED_ERROR, $command->actionSmoke());
        self::assertStringContainsString('2 elements: 0 passed, 0 skipped, 2 failed.', $command->output);
        self::assertStringContainsString('import answered 500', $command->output);
    }

    public function testADraftCreatedBeforeAnImportTransportErrorIsFoundByItsKeyAndDeleted(): void
    {
        $command = $this->command(dropImportResponses: true);
        $command->uri = $this->pageUri;
        $command->site = 'default';
        $command->probe = 0;

        self::assertSame(ExitCode::UNSPECIFIED_ERROR, $command->actionSmoke());
        self::assertStringContainsString('ConnectException', $command->output);
        // The import did create its draft; the command found it by its notes and removed it.
        self::assertSame(1, $this->draftsBeforeTransportError);
        self::assertSame(0, $this->draftCount());
    }

    public function testAnExportOfAnotherElementFailsWithoutTouchingDrafts(): void
    {
        $command = $this->command(exportElementId: 999);
        $command->uri = $this->pageUri;
        $command->site = 'default';

        self::assertSame(ExitCode::UNSPECIFIED_ERROR, $command->actionSmoke());
        self::assertStringContainsString('export returned element 999', $command->output);
        self::assertSame(['GET export'], $this->requests);
    }

    private function command(bool $failImports = false, bool $dropImportResponses = false, ?int $exportElementId = null): CapturingTextFlowController
    {
        $command = new CapturingTextFlowController('text-flow', $this->plugin());

        $command->httpHandler = function(RequestInterface $request) use ($failImports, $dropImportResponses, $exportElementId) {
            $action = basename($request->getUri()->getPath());
            $this->requests[] = $request->getMethod() . ' ' . $action;

            if ($failImports && $action === 'import') {
                return Create::promiseFor(new PsrResponse(500, [], '{"error":"boom"}'));
            }

            parse_str($request->getUri()->getQuery(), $query);
            $apiKey = substr($request->getHeaderLine('Authorization'), strlen('Bearer '));
            $response = $this->textAction($action, $query, (string)$request->getBody(), $apiKey);

            if ($dropImportResponses && $action === 'import') {
                $this->draftsBeforeTransportError = $this->draftCount();

                return Create::rejectionFor(new ConnectException('Connection reset', $request));
            }

            if ($exportElementId !== null && $action === 'export') {
                $response->data['element']['id'] = $exportElementId;
            }

            return Create::promiseFor(new PsrResponse($response->getStatusCode(), [], (string)json_encode($response->data)));
        };

        return $command;
    }
}
