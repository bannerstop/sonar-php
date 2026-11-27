<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests;

use Bannerstop\Sonar\Tests\Support\Process;
use Bannerstop\Sonar\Tests\Support\StubServer;
use Bannerstop\Sonar\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * The registered handlers in a real process with the default transport against a stub server: what PHP does with
 * an uncaught exception or a fatal error stays as it was, and the item still arrives.
 */
final class ErrorProcessTest extends TestCase
{
    /** @var StubServer */
    private $server;

    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->server = new StubServer();
        $this->dir = TempDir::create();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        TempDir::remove($this->dir);
    }

    /**
     * @return array{int, string}
     */
    private function runWorker(string $scenario): array
    {
        return (new Process([PHP_BINARY, __DIR__.'/Support/error-worker.php', $this->server->getUrl(), $this->dir.'/buffer', $scenario]))->wait();
    }

    public function testAnUncaughtExceptionIsReportedAndStillEndsTheProcessAsPhpDoes(): void
    {
        $this->server->start();

        [$code, $output] = $this->runWorker('uncaught');

        self::assertSame(255, $code);
        self::assertStringContainsString('Uncaught RuntimeException: uncaught in the worker', $output);
        $requests = $this->server->getRequests();
        self::assertCount(1, $requests);
        self::assertSame('/intake/v1/errors', $requests[0]['uri']);
        self::assertSame('uncaught in the worker', json_decode($requests[0]['body'], true)['exception']['message']);
    }

    public function testAPreviousExceptionHandlerStillRuns(): void
    {
        $this->server->start();

        [$code, $output] = $this->runWorker('previous-handler');

        self::assertSame(0, $code);
        self::assertStringContainsString('previous handler saw: chained', $output);
        self::assertCount(1, $this->server->getRequests());
    }

    public function testAFatalErrorIsReportedAsCritical(): void
    {
        $this->server->start();

        $this->runWorker('fatal'); // the exit code of a fatal error differs between PHP builds, so it is not asserted

        $requests = $this->server->getRequests();
        self::assertCount(1, $requests);
        $body = json_decode($requests[0]['body'], true);
        self::assertSame('critical', $body['level']);
        self::assertSame('E_ERROR', $body['exception']['class']);
    }

    public function testAnUnreachableSonarDoesNotChangeTheExitCode(): void
    {
        $this->server->start();
        $this->server->stop();

        [$code, $output] = $this->runWorker('caught-and-reported');

        self::assertSame(0, $code);
        self::assertStringContainsString('still running', $output);
    }

    public function testASlowSonarDelaysTheProcessByTheShortTimeoutAtMost(): void
    {
        $this->server->start();
        $this->server->answer('sleep:5000:202');

        $started = microtime(true);
        [$code] = $this->runWorker('caught-and-reported');

        self::assertSame(0, $code);
        self::assertLessThan(4.0, microtime(true) - $started);
    }
}
