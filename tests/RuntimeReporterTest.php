<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests;

use Bannerstop\Sonar\RuntimeReporter;
use Bannerstop\Sonar\Tests\Support\CapturesErrorLog;
use Bannerstop\Sonar\Tests\Support\FakeClock;
use Bannerstop\Sonar\Tests\Support\TempDir;
use Bannerstop\Sonar\Transport\InMemoryTransport;
use PHPUnit\Framework\TestCase;

/**
 * The runtime report: when it is due, that it is sent after the response, and that it never hurts the request.
 */
final class RuntimeReporterTest extends TestCase
{
    use CapturesErrorLog;

    /** @var string */
    private $dir;

    /** @var InMemoryTransport */
    private $transport;

    /** @var FakeClock */
    private $clock;

    /** @var list<callable> tasks the "response has gone out" has not run yet */
    private $deferred = [];

    protected function setUp(): void
    {
        $this->dir = TempDir::create();
        $this->transport = new InMemoryTransport();
        $this->clock = new FakeClock();
        $this->deferred = [];
    }

    protected function tearDown(): void
    {
        $this->releaseErrorLog();
        TempDir::remove($this->dir);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function reporter(array $options = []): RuntimeReporter
    {
        return new RuntimeReporter('https://sonar.example.com/', 'sonar_pls_test', 'checkout', $options + [
            'transport' => $this->transport,
            'markerDir' => $this->dir.'/marker',
            'release' => 'release-1',
            'clock' => $this->clock,
            'afterResponse' => function (callable $task): void {
                $this->deferred[] = $task;
            },
        ]);
    }

    private function responseHasGoneOut(): void
    {
        while ([] !== $this->deferred) {
            array_shift($this->deferred)();
        }
    }

    public function testItReportsPhpSapiPackageAndServiceToTheRuntimeEndpoint(): void
    {
        $this->reporter(['sapi' => 'web'])->reportIfDue();
        $this->responseHasGoneOut();

        self::assertCount(1, $this->transport->getRequests());
        $request = $this->transport->getRequests()[0];
        self::assertSame('https://sonar.example.com/intake/v1/runtime', $request->getUrl());
        self::assertSame('Bearer sonar_pls_test', $request->getHeader('Authorization'));
        self::assertSame(['service' => 'checkout', 'sapi' => 'web', 'php' => PHP_VERSION, 'package' => RuntimeReporter::PACKAGE_VERSION], $request->getJson());
    }

    public function testNothingIsSentBeforeTheResponseHasGoneOut(): void
    {
        $this->reporter(['sapi' => 'web'])->reportIfDue();

        self::assertCount(0, $this->transport->getRequests());
        self::assertCount(1, $this->deferred);
    }

    public function testTheCommandLineIsReportedAsCli(): void
    {
        $this->reporter(['sapi' => 'cli'])->reportIfDue();
        $this->responseHasGoneOut();

        self::assertSame('cli', $this->transport->getRequests()[0]->getJson()['sapi']);
    }

    public function testTheSapiIsDetectedWhenNotGiven(): void
    {
        $this->reporter()->reportIfDue();
        $this->responseHasGoneOut();

        // the tests run on the command line
        self::assertSame('cli', $this->transport->getRequests()[0]->getJson()['sapi']);
    }

    public function testOrdinaryRequestsAfterTheReportDoNothing(): void
    {
        $this->reporter()->reportIfDue();
        $this->responseHasGoneOut();

        $this->clock->advance(3600);
        $this->reporter()->reportIfDue();

        self::assertCount(0, $this->deferred);
        self::assertCount(1, $this->transport->getRequests());
    }

    public function testItReportsAgainAfterADay(): void
    {
        $this->reporter()->reportIfDue();
        $this->responseHasGoneOut();

        $this->clock->advance(86400 - 1);
        $this->reporter()->reportIfDue();
        $this->responseHasGoneOut();
        self::assertCount(1, $this->transport->getRequests());

        $this->clock->advance(1);
        $this->reporter()->reportIfDue();
        $this->responseHasGoneOut();
        self::assertCount(2, $this->transport->getRequests());
    }

    public function testANewReleaseReportsAtOnceTheSameDay(): void
    {
        $this->reporter()->reportIfDue();
        $this->responseHasGoneOut();

        $this->clock->advance(60);
        $this->reporter(['release' => 'release-2'])->reportIfDue();
        $this->responseHasGoneOut();

        self::assertCount(2, $this->transport->getRequests());
    }

    public function testWebAndCommandLineAreDueIndependently(): void
    {
        $this->reporter(['sapi' => 'web'])->reportIfDue();
        $this->responseHasGoneOut();
        $this->reporter(['sapi' => 'cli'])->reportIfDue();
        $this->responseHasGoneOut();
        $this->reporter(['sapi' => 'cli'])->reportIfDue();
        $this->responseHasGoneOut();

        self::assertSame(['web', 'cli'], array_map(function ($request) {
            return $request->getJson()['sapi'];
        }, $this->transport->getRequests()));
    }

    public function testTwoRequestsAtOnceReportOnce(): void
    {
        $first = $this->reporter();
        $second = $this->reporter();
        $first->reportIfDue();
        $second->reportIfDue();
        $this->responseHasGoneOut();

        self::assertCount(1, $this->transport->getRequests());
    }

    public function testAnUnreachableSonarIsSwallowedAndLoggedOncePerDay(): void
    {
        $this->captureErrorLog($this->dir.'/error.log');
        $this->transport->failWith('connection refused')->failWith('connection refused')->failWith('connection refused');

        $this->reporter()->reportIfDue();
        $this->responseHasGoneOut();
        self::assertStringContainsString('connection refused', $this->capturedErrorLog());
        self::assertStringNotContainsString('sonar_pls_test', $this->capturedErrorLog());

        $this->clock->advance(900);
        $this->reporter()->reportIfDue();
        $this->responseHasGoneOut();
        self::assertCount(2, $this->transport->getRequests());
        self::assertSame(1, substr_count($this->capturedErrorLog(), 'failed'));

        $this->clock->advance(86400);
        $this->reporter()->reportIfDue();
        $this->responseHasGoneOut();
        self::assertSame(2, substr_count($this->capturedErrorLog(), 'failed'));
    }

    public function testARefusalIsSwallowedAndLogsTheStatusAndTitle(): void
    {
        $this->captureErrorLog($this->dir.'/error.log');
        $this->transport->respondWith(422, '{"title":"Unknown service \"checkout\""}');

        $this->reporter()->reportIfDue();
        $this->responseHasGoneOut();

        self::assertStringContainsString('422', $this->capturedErrorLog());
        self::assertStringContainsString('Unknown service', $this->capturedErrorLog());
    }

    public function testAFailedReportIsNotRetriedBeforeTheBackoffIsOver(): void
    {
        $this->captureErrorLog($this->dir.'/error.log');
        $this->transport->respondWith(503);

        $this->reporter()->reportIfDue();
        $this->responseHasGoneOut();
        $this->clock->advance(899);
        $this->reporter()->reportIfDue();

        self::assertCount(0, $this->deferred);
        self::assertCount(1, $this->transport->getRequests());
    }

    public function testAFailedReportIsReportedWhenSonarIsBackWithinTheDay(): void
    {
        $this->captureErrorLog($this->dir.'/error.log');
        $this->transport->respondWith(503);
        $this->reporter()->reportIfDue();
        $this->responseHasGoneOut();

        $this->clock->advance(900);
        $this->reporter()->reportIfDue();
        $this->responseHasGoneOut();
        $this->clock->advance(3600);
        $this->reporter()->reportIfDue();

        self::assertCount(2, $this->transport->getRequests());
        self::assertCount(0, $this->deferred);
    }

    public function testASchedulerThatFailsNeverReachesTheRequest(): void
    {
        $this->captureErrorLog($this->dir.'/error.log');
        $reporter = $this->reporter(['afterResponse' => function (callable $task): void {
            throw new \RuntimeException('scheduling failed');
        }]);

        $reporter->reportIfDue();

        self::assertCount(0, $this->transport->getRequests());
    }

    public function testAnUnwritableMarkerMeansNoReportInsteadOfOneOnEveryRequest(): void
    {
        file_put_contents($this->dir.'/blocked', 'a file, not a directory');
        $reporter = $this->reporter(['markerDir' => $this->dir.'/blocked/marker']);

        $reporter->reportIfDue();
        $this->responseHasGoneOut();
        $reporter->reportIfDue();
        $this->responseHasGoneOut();

        self::assertCount(0, $this->transport->getRequests());
    }

    public function testItNeedsUrlTokenAndService(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RuntimeReporter('https://sonar.example.com', 'token', '');
    }

    public function testAnUnknownOptionIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RuntimeReporter('https://sonar.example.com', 'token', 'checkout', ['colour' => 'red']);
    }

    public function testThePackageVersionIsTheOneOfTheChangelogAndFitsTheContract(): void
    {
        self::assertSame(1, preg_match('/^## (\S+)/m', (string) file_get_contents(__DIR__.'/../CHANGELOG.md'), $match));
        self::assertSame($match[1], RuntimeReporter::PACKAGE_VERSION);
        self::assertSame(1, preg_match('/^[0-9]{1,9}(\.[0-9]{1,9}){1,3}$/', RuntimeReporter::PACKAGE_VERSION));
    }
}
