<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests;

use Bannerstop\Sonar\Run;
use Bannerstop\Sonar\Sonar;
use Bannerstop\Sonar\Tests\Support\CapturesErrorLog;
use Bannerstop\Sonar\Tests\Support\TempDir;
use Bannerstop\Sonar\Transport\InMemoryTransport;
use PHPUnit\Framework\TestCase;

/**
 * The static facade, configured from the environment or explicitly.
 */
final class SonarTest extends TestCase
{
    use CapturesErrorLog;

    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        Sonar::reset();
        putenv('SONAR_URL');
        putenv('SONAR_TOKEN');
        $this->dir = TempDir::create();
    }

    protected function tearDown(): void
    {
        Sonar::reset();
        putenv('SONAR_URL');
        putenv('SONAR_TOKEN');
        $this->releaseErrorLog();
        TempDir::remove($this->dir);
    }

    public function testItIsConfiguredFromTheEnvironment(): void
    {
        $transport = new InMemoryTransport();
        putenv('SONAR_URL=https://sonar.example.com/');
        putenv('SONAR_TOKEN=env-token');
        Sonar::configure(null, null, ['transport' => $transport, 'bufferDir' => $this->dir.'/buffer']);

        $result = Sonar::pulse('check', ['count' => 3]);

        self::assertTrue($result->isSent());
        $request = $transport->getRequests()[0];
        self::assertSame('https://sonar.example.com/intake/v1/pulse/check', $request->getUrl());
        self::assertSame('Bearer env-token', $request->getHeader('Authorization'));
        self::assertSame(3, $request->getJson()['count']);
    }

    public function testItCanBeConfiguredExplicitly(): void
    {
        $transport = new InMemoryTransport();
        Sonar::configure('https://other.example.com', 'explicit-token', ['transport' => $transport, 'bufferDir' => $this->dir.'/buffer']);

        Sonar::pulse('check');

        self::assertSame('https://other.example.com/intake/v1/pulse/check', $transport->getRequests()[0]->getUrl());
        self::assertSame('Bearer explicit-token', $transport->getRequests()[0]->getHeader('Authorization'));
    }

    public function testWithoutConfigurationPulseNeverThrows(): void
    {
        $this->captureErrorLog($this->dir.'/php-error.log');

        $result = Sonar::pulse('check');

        self::assertTrue($result->isRejected());
        self::assertStringContainsString('SONAR_URL', $this->capturedErrorLog());
    }

    public function testTheMissingConfigurationIsLoggedOncePerProcess(): void
    {
        $this->captureErrorLog($this->dir.'/php-error.log');

        Sonar::pulse('check');
        Sonar::pulse('check');
        Sonar::run('check', function (Run $run) {
        });

        self::assertSame(1, substr_count($this->capturedErrorLog(), 'SONAR_URL'));
    }

    public function testTheBufferDirComesFromTheEnvironmentToo(): void
    {
        $transport = new InMemoryTransport();
        putenv('SONAR_URL=https://sonar.example.com');
        putenv('SONAR_TOKEN=env-token');
        putenv('SONAR_BUFFER_DIR='.$this->dir.'/env-buffer');
        try {
            Sonar::configure(null, null, ['transport' => $transport]);
            $transport->failWith();

            Sonar::pulse('check');
        } finally {
            putenv('SONAR_BUFFER_DIR');
        }

        self::assertCount(1, glob($this->dir.'/env-buffer/*.json') ?: []);
    }

    public function testWithoutConfigurationRunStillRunsTheJob(): void
    {
        $this->captureErrorLog($this->dir.'/php-error.log');
        $result = Sonar::run('check', function (Run $run) {
            return 'done';
        });

        self::assertSame('done', $result);
    }

    public function testWithoutConfigurationAFailingJobStillFailsAsBefore(): void
    {
        $this->captureErrorLog($this->dir.'/php-error.log');
        $this->expectException(\LogicException::class);

        Sonar::run('check', function (Run $run) {
            throw new \LogicException('boom');
        });
    }

    public function testRunGoesThroughTheConfiguredClient(): void
    {
        $transport = new InMemoryTransport();
        Sonar::configure('https://sonar.example.com', 'token', ['transport' => $transport, 'bufferDir' => $this->dir.'/buffer']);

        Sonar::run('check', function (Run $run) {
            $run->setCount(2);
        });

        self::assertSame(2, $transport->getRequests()[0]->getJson()['count']);
    }

    public function testReportRuntimeUsesTheEnvironmentAndTheServiceSlug(): void
    {
        $transport = new InMemoryTransport();
        $deferred = [];
        putenv('SONAR_URL=https://sonar.example.com');
        putenv('SONAR_TOKEN=env-token');
        putenv('SONAR_SERVICE=checkout');
        try {
            Sonar::reportRuntime(null, [
                'transport' => $transport,
                'markerDir' => $this->dir.'/marker',
                'afterResponse' => function (callable $task) use (&$deferred): void {
                    $deferred[] = $task;
                },
            ]);
        } finally {
            putenv('SONAR_SERVICE');
        }
        foreach ($deferred as $task) {
            $task();
        }

        $request = $transport->getRequests()[0];
        self::assertSame('https://sonar.example.com/intake/v1/runtime', $request->getUrl());
        self::assertSame('checkout', $request->getJson()['service']);
    }

    public function testReportRuntimeWithoutAnyConfigurationStaysQuiet(): void
    {
        $this->captureErrorLog($this->dir.'/php-error.log');
        putenv('SONAR_SERVICE');

        Sonar::reportRuntime();

        self::assertSame('', $this->capturedErrorLog());
    }

    public function testReportRuntimeWithHalfAConfigurationLogsOncePerProcessAndNeverThrows(): void
    {
        $this->captureErrorLog($this->dir.'/php-error.log');
        putenv('SONAR_URL=https://sonar.example.com');
        putenv('SONAR_TOKEN=env-token');

        Sonar::reportRuntime();
        Sonar::reportRuntime();

        self::assertSame(1, substr_count($this->capturedErrorLog(), 'SONAR_SERVICE'));
    }
}
