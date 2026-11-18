<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests;

use Bannerstop\Sonar\SonarClient;
use Bannerstop\Sonar\Tests\Support\CapturesErrorLog;
use Bannerstop\Sonar\Tests\Support\FakeClock;
use Bannerstop\Sonar\Tests\Support\TempDir;
use Bannerstop\Sonar\Transport\InMemoryTransport;
use Bannerstop\Sonar\Transport\Transport;
use Bannerstop\Sonar\Transport\TransportResponse;
use PHPUnit\Framework\TestCase;

/**
 * Buffering and flushing through the public API and an InMemoryTransport.
 */
final class BufferTest extends TestCase
{
    use CapturesErrorLog;

    /** @var string */
    private $dir;

    /** @var InMemoryTransport */
    private $transport;

    /** @var FakeClock */
    private $clock;

    /** @var string|false */
    private $previousTmpDir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create();
        $this->transport = new InMemoryTransport();
        $this->clock = new FakeClock();
        $this->previousTmpDir = getenv('TMPDIR');
    }

    protected function tearDown(): void
    {
        $this->releaseErrorLog();
        putenv(false === $this->previousTmpDir ? 'TMPDIR' : 'TMPDIR='.$this->previousTmpDir);
        TempDir::remove($this->dir);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function client(array $options = [], string $token = 'invented-token'): SonarClient
    {
        $this->captureErrorLog($this->dir.'/php-error.log');

        return new SonarClient('https://sonar.example.com', $token, $options + [
            'transport' => $this->transport,
            'bufferDir' => $this->dir.'/buffer',
            'clock' => $this->clock,
        ]);
    }

    /**
     * Buffers $count Pulses of the check "job", one minute apart, while the Main is down.
     *
     * @return list<string> their ids
     */
    private function bufferPulses(SonarClient $client, int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; ++$i) {
            $this->transport->failWith();
            $this->clock->advance(60);
            $ids[] = $client->pulse('job')->getId();
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function sentIds(int $from = 0): array
    {
        return array_map(function ($request) {
            return $request->getJson()['id'];
        }, array_slice($this->transport->getRequests(), $from));
    }

    private function files(): array
    {
        return array_values(glob($this->dir.'/buffer/*.json') ?: []);
    }

    public function testEachBufferedPulseIsOneJsonFileWithTheCheckAndTheBody(): void
    {
        $client = $this->client();
        $ids = $this->bufferPulses($client, 1);

        $files = $this->files();
        self::assertCount(1, $files);
        $entry = json_decode((string) file_get_contents($files[0]), true);
        self::assertSame('job', $entry['check']);
        self::assertSame($ids[0], $entry['body']['id']);
        self::assertSame('2026-11-18T10:01:00.000000+00:00', $entry['body']['ranAt']);
        self::assertSame([], glob($this->dir.'/buffer/.*.tmp') ?: [], 'no temporary file is left behind');
    }

    public function testTheDefaultBufferIsPerUrlAndTokenUnderTheTempDir(): void
    {
        $before = glob(sys_get_temp_dir().'/sonar-php/*', GLOB_ONLYDIR) ?: [];
        $this->transport->failWith()->failWith();
        $suffix = bin2hex(random_bytes(4));

        $this->client(['bufferDir' => null], 'token-a-'.$suffix)->pulse('job');
        $this->client(['bufferDir' => null], 'token-b-'.$suffix)->pulse('job');

        $created = array_values(array_diff(glob(sys_get_temp_dir().'/sonar-php/*', GLOB_ONLYDIR) ?: [], $before));
        try {
            self::assertCount(2, $created);
            foreach ($created as $buffer) {
                self::assertCount(1, glob($buffer.'/*.json') ?: []);
            }
        } finally {
            foreach ($created as $buffer) {
                TempDir::remove($buffer);
            }
        }
    }

    public function testAPulseOfAnotherTokenIsNeverFlushedByThisOne(): void
    {
        $before = glob(sys_get_temp_dir().'/sonar-php/*', GLOB_ONLYDIR) ?: [];
        $suffix = bin2hex(random_bytes(4));
        $this->transport->failWith();
        $this->client(['bufferDir' => null], 'token-a-'.$suffix)->pulse('job');
        $requests = count($this->transport->getRequests());

        try {
            $this->client(['bufferDir' => null], 'token-b-'.$suffix)->pulse('job');

            self::assertCount($requests + 1, $this->transport->getRequests(), 'only the new Pulse was sent');
        } finally {
            foreach (array_diff(glob(sys_get_temp_dir().'/sonar-php/*', GLOB_ONLYDIR) ?: [], $before) as $buffer) {
                TempDir::remove($buffer);
            }
        }
    }

    public function testTheBufferKeepsTheNewestPulsesWhenItsCapIsReached(): void
    {
        $client = $this->client(['bufferLimit' => 3]);
        $ids = $this->bufferPulses($client, 5);
        self::assertCount(3, $this->files());
        $before = count($this->transport->getRequests());

        $client->pulse('job');

        self::assertSame([$ids[2], $ids[3], $ids[4]], array_slice($this->sentIds($before), 0, 3));
        self::assertStringContainsString('sonar-php', $this->capturedErrorLog());
    }

    public function testBufferedPulsesOlderThanSevenDaysAreDroppedUnsent(): void
    {
        $client = $this->client();
        $this->bufferPulses($client, 1);
        $before = count($this->transport->getRequests());
        $this->clock->advance(7 * 86400 + 120);

        $new = $client->pulse('job');

        self::assertSame([$new->getId()], $this->sentIds($before));
        self::assertSame([], $this->files());
    }

    public function testAPulseRightAtTheEdgeOfSevenDaysIsStillSent(): void
    {
        $client = $this->client();
        $ids = $this->bufferPulses($client, 1);
        $before = count($this->transport->getRequests());
        $this->clock->advance(7 * 86400 - 60);

        $client->pulse('job');

        self::assertSame($ids[0], $this->sentIds($before)[0]);
    }

    public function testAFlushStopsAtTheFirstFailureAndKeepsTheRest(): void
    {
        $client = $this->client();
        $ids = $this->bufferPulses($client, 3);
        $before = count($this->transport->getRequests());
        $this->transport->respondWith(202)->respondWith(503);

        $new = $client->pulse('job');

        self::assertSame([$ids[0], $ids[1]], $this->sentIds($before), 'the new Pulse was not even tried');
        self::assertTrue($new->isBuffered());
        self::assertCount(3, $this->files(), 'the 2nd, the 3rd and the new Pulse are left');
    }

    public function testAnEntryRejectedForGoodDuringAFlushIsDeletedAndTheFlushGoesOn(): void
    {
        $client = $this->client();
        $ids = $this->bufferPulses($client, 2);
        $before = count($this->transport->getRequests());
        $this->transport->respondWith(422, '{"title":"Invented"}');

        $new = $client->pulse('job');

        self::assertSame([$ids[0], $ids[1], $new->getId()], $this->sentIds($before));
        self::assertSame([], $this->files());
    }

    public function testAFlushSendsAtMostTheBudgetAndThenTheNewPulse(): void
    {
        $client = $this->client(['flushLimit' => 2]);
        $ids = $this->bufferPulses($client, 4);
        $before = count($this->transport->getRequests());

        $new = $client->pulse('job');

        self::assertSame([$ids[0], $ids[1], $new->getId()], $this->sentIds($before));
        self::assertCount(2, $this->files(), 'the rest waits for the next call');
    }

    public function testAFlushStopsWhenItsTimeBudgetIsUsedUp(): void
    {
        $clock = $this->clock;
        $slow = new class ($clock) implements Transport {
            /** @var FakeClock */
            private $clock;

            /** @var list<string> */
            public $urls = [];

            /** @var bool */
            public $down = true;

            public function __construct(FakeClock $clock)
            {
                $this->clock = $clock;
            }

            public function send(string $url, array $headers, string $body): TransportResponse
            {
                if ($this->down) {
                    throw new \Bannerstop\Sonar\Transport\TransportException('down');
                }
                $this->urls[] = $url;
                $this->clock->advance(2); // each request takes two seconds

                return new TransportResponse(202);
            }
        };
        $client = $this->client(['transport' => $slow, 'flushSeconds' => 3.0]);
        for ($i = 0; $i < 4; ++$i) {
            $this->clock->advance(60);
            $client->pulse('job');
        }
        $slow->down = false;

        $client->pulse('job');

        // 2 s each: the flush starts at 0 s, sends at 0 s and 2 s, stops at 4 s >= 3 s; then the new Pulse
        self::assertCount(3, $slow->urls);
        self::assertCount(2, $this->files(), 'the third and the fourth Pulse are left');
    }

    public function testAFlushHeldByAnotherProcessIsSkippedAndTheNewPulseIsSent(): void
    {
        $client = $this->client();
        $this->bufferPulses($client, 2);
        $before = count($this->transport->getRequests());
        $lock = fopen($this->dir.'/buffer/.lock', 'c');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        $new = $client->pulse('job');

        self::assertSame([$new->getId()], $this->sentIds($before));
        self::assertCount(2, $this->files());
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    public function testAnUnusableBufferNeverThrowsAndTheLostPulseIsReportedAsRejected(): void
    {
        file_put_contents($this->dir.'/not-a-dir', 'x');
        $this->transport->failWith();

        $result = $this->client(['bufferDir' => $this->dir.'/not-a-dir/buffer'])->pulse('job');

        self::assertTrue($result->isRejected());
        self::assertStringContainsString('buffer', (string) $result->getMessage());
        self::assertStringContainsString('sonar-php', $this->capturedErrorLog());
    }

    public function testFlushSendsEverythingAndReportsWhatHappened(): void
    {
        $client = $this->client(['flushLimit' => 1]);
        $ids = $this->bufferPulses($client, 3);
        $before = count($this->transport->getRequests());

        $report = $client->flush();

        self::assertSame($ids, $this->sentIds($before));
        self::assertSame(3, $report->getSent());
        self::assertSame(0, $report->getDropped());
        self::assertSame(0, $report->getRemaining());
        self::assertFalse($report->hasFailed());
    }

    public function testFlushReportsAFailureAndWhatRemains(): void
    {
        $client = $this->client();
        $this->bufferPulses($client, 2);
        $this->transport->failWith();

        $report = $client->flush();

        self::assertSame(0, $report->getSent());
        self::assertSame(2, $report->getRemaining());
        self::assertTrue($report->hasFailed());
    }
}
