<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests;

use Bannerstop\Sonar\Run;
use Bannerstop\Sonar\SonarClient;
use Bannerstop\Sonar\Tests\Support\FakeClock;
use Bannerstop\Sonar\Tests\Support\TempDir;
use Bannerstop\Sonar\Transport\InMemoryTransport;
use PHPUnit\Framework\TestCase;

final class RunTest extends TestCase
{
    /** @var string */
    private $dir;

    /** @var InMemoryTransport */
    private $transport;

    /** @var FakeClock */
    private $clock;

    /** @var SonarClient */
    private $client;

    protected function setUp(): void
    {
        $this->dir = TempDir::create();
        $this->transport = new InMemoryTransport();
        $this->clock = new FakeClock();
        $this->client = new SonarClient('https://sonar.example.com', 'invented-token', [
            'transport' => $this->transport,
            'bufferDir' => $this->dir.'/buffer',
            'clock' => $this->clock,
        ]);
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastBody(): array
    {
        $requests = $this->transport->getRequests();

        return end($requests)->getJson();
    }

    public function testASuccessfulRunSendsOkWithTheMeasuredDurationAndReturnsTheResultOfTheJob(): void
    {
        $result = $this->client->run('import', function (Run $run) {
            $this->clock->advance(41.7);

            return 'the return value';
        });

        self::assertSame('the return value', $result);
        $body = $this->lastBody();
        self::assertSame('ok', $body['status']);
        self::assertSame(41.7, $body['duration']);
        self::assertSame('2026-11-18T10:00:00.000000+00:00', $body['ranAt'], 'ranAt is when the job started');
    }

    public function testTheJobSetsCountValuesLogAndStatusOnTheRunObject(): void
    {
        $this->client->run('import', function (Run $run) {
            $run->setCount(1280)->setValues(['imported' => 1280, 'skipped' => 3])->setLog('done')->setStatus('warn');
        });

        $body = $this->lastBody();
        self::assertSame(1280, $body['count']);
        self::assertSame(['imported' => 1280, 'skipped' => 3], $body['values']);
        self::assertSame('done', $body['log']);
        self::assertSame('warn', $body['status']);
    }

    public function testAFailingJobSendsFailWithTheMessageAsLogAndRethrows(): void
    {
        $caught = null;
        try {
            $this->client->run('import', function (Run $run) {
                $run->setCount(7)->setLog('half way');
                $this->clock->advance(3);

                throw new \DomainException('the database is gone');
            });
        } catch (\DomainException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'the exception is rethrown unchanged');
        self::assertSame('the database is gone', $caught->getMessage());
        $body = $this->lastBody();
        self::assertSame('fail', $body['status']);
        self::assertSame('the database is gone', $body['log']);
        self::assertSame(7, $body['count']);
        self::assertSame(3, $body['duration']);
    }

    public function testTheMessageOfAFailureIsCutToTheLogLimit(): void
    {
        try {
            $this->client->run('import', function (Run $run) {
                throw new \RuntimeException(str_repeat('x', 70000));
            });
            self::fail('expected the exception');
        } catch (\RuntimeException $e) {
            self::assertSame(65536, strlen($this->lastBody()['log']));
        }
    }

    public function testAnErrorThrowIsReportedAndRethrownToo(): void
    {
        try {
            $this->client->run('import', function (Run $run) {
                return intdiv(1, 0);
            });
            self::fail('expected the error');
        } catch (\DivisionByZeroError $e) {
            self::assertSame('fail', $this->lastBody()['status']);
        }
    }

    public function testABrokenMonitoringNeverChangesTheOutcomeOfTheJob(): void
    {
        $this->transport->failWith();

        $result = $this->client->run('import', function (Run $run) {
            return 42;
        });

        self::assertSame(42, $result);
    }

    public function testTheBufferIsFlushedBeforeTheRunsPulseIsSent(): void
    {
        $this->transport->failWith();
        $first = $this->client->pulse('other');
        $this->clock->advance(10);

        $this->client->run('import', function (Run $run) {
        });

        $ids = array_map(function ($request) {
            return $request->getJson()['id'];
        }, array_slice($this->transport->getRequests(), 1));
        self::assertSame($first->getId(), $ids[0]);
        self::assertCount(2, $ids);
    }
}
