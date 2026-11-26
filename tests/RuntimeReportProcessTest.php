<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests;

use Bannerstop\Sonar\Tests\Support\Process;
use Bannerstop\Sonar\Tests\Support\StubServer;
use Bannerstop\Sonar\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * The runtime report with the default scheduling and transport, in a real process against a stub server.
 */
final class RuntimeReportProcessTest extends TestCase
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
    private function runWorker(): array
    {
        return (new Process([PHP_BINARY, __DIR__.'/Support/runtime-worker.php', $this->server->getUrl(), $this->dir.'/marker']))->wait();
    }

    public function testTheReportGoesOutAtShutdownAndOnlyOnce(): void
    {
        $this->server->start();

        [$code, $output] = $this->runWorker();
        $this->runWorker();

        self::assertSame(0, $code);
        self::assertSame('request done', $output);
        $requests = $this->server->getRequests();
        self::assertCount(1, $requests);
        self::assertSame('/intake/v1/runtime', $requests[0]['uri']);
        self::assertSame('cli', json_decode($requests[0]['body'], true)['sapi']);
    }

    public function testAnUnreachableSonarDoesNotChangeTheOutputOrTheExitCode(): void
    {
        $this->server->start();
        $this->server->stop();

        [$code, $output] = $this->runWorker();

        self::assertSame(0, $code);
        self::assertStringStartsWith('request done', $output);
    }

    public function testASlowSonarDelaysTheProcessByTheShortTimeoutAtMost(): void
    {
        $this->server->start();
        $this->server->answer('sleep:5000:202');

        $started = microtime(true);
        [$code] = $this->runWorker();

        self::assertSame(0, $code);
        self::assertLessThan(4.0, microtime(true) - $started);
    }
}
