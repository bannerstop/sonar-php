<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests;

use Bannerstop\Sonar\PulseResult;
use Bannerstop\Sonar\SonarClient;
use Bannerstop\Sonar\Tests\Support\CapturesErrorLog;
use Bannerstop\Sonar\Tests\Support\FakeClock;
use Bannerstop\Sonar\Tests\Support\TempDir;
use Bannerstop\Sonar\Transport\InMemoryTransport;
use PHPUnit\Framework\TestCase;

/**
 * The public API of SonarClient through an InMemoryTransport.
 */
final class PulseTest extends TestCase
{
    use CapturesErrorLog;

    private const URL = 'https://sonar.example.com';
    private const TOKEN = 'sonar_tok_invented_secret_value';

    /** @var string */
    private $dir;

    /** @var InMemoryTransport */
    private $transport;

    /** @var FakeClock */
    private $clock;

    protected function setUp(): void
    {
        $this->dir = TempDir::create();
        $this->transport = new InMemoryTransport();
        $this->clock = new FakeClock();
    }

    protected function tearDown(): void
    {
        $this->releaseErrorLog();
        TempDir::remove($this->dir);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function client(array $options = []): SonarClient
    {
        $this->captureErrorLog($this->dir.'/php-error.log');

        return new SonarClient(self::URL, self::TOKEN, $options + [
            'transport' => $this->transport,
            'bufferDir' => $this->dir.'/buffer',
            'clock' => $this->clock,
        ]);
    }

    /**
     * @return list<string>
     */
    private function bufferFiles(?string $dir = null): array
    {
        return array_values(glob(($dir ?? $this->dir.'/buffer').'/*.json') ?: []);
    }

    public function testASuccessfulPulseIsPostedToTheCheckWithTheBearerToken(): void
    {
        $result = $this->client()->pulse('nightly-import');

        self::assertTrue($result->isSent());
        self::assertSame(PulseResult::SENT, $result->getOutcome());
        $requests = $this->transport->getRequests();
        self::assertCount(1, $requests);
        self::assertSame('https://sonar.example.com/intake/v1/pulse/nightly-import', $requests[0]->getUrl());
        self::assertSame('Bearer '.self::TOKEN, $requests[0]->getHeader('Authorization'));
        self::assertSame('application/json', $requests[0]->getHeader('Content-Type'));
    }

    public function testTheBodyHasAUuidV4IdAnOffsetRanAtAndStatusOk(): void
    {
        $result = $this->client()->pulse('nightly-import');

        $body = $this->transport->getRequests()[0]->getJson();
        self::assertSame(1, preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $body['id']));
        self::assertSame($body['id'], $result->getId());
        self::assertSame('2026-11-18T10:00:00.000000+00:00', $body['ranAt']);
        self::assertSame('ok', $body['status']);
        self::assertSame(['id', 'ranAt', 'status'], array_keys($body));
    }

    public function testTheFieldsOfThePulseAreSent(): void
    {
        $this->client()->pulse('nightly-import', [
            'status' => 'warn',
            'count' => 1280,
            'duration' => 41.7,
            'values' => ['imported' => 1280, 'skipped' => 3],
            'log' => "done\n",
        ]);

        $body = $this->transport->getRequests()[0]->getJson();
        self::assertSame('warn', $body['status']);
        self::assertSame(1280, $body['count']);
        self::assertSame(41.7, $body['duration']);
        self::assertSame(['imported' => 1280, 'skipped' => 3], $body['values']);
        self::assertSame("done\n", $body['log']);
    }

    public function testACheckNameIsUrlEncodedInThePath(): void
    {
        $this->client()->pulse('nightly import é');

        self::assertSame('https://sonar.example.com/intake/v1/pulse/nightly%20import%20%C3%A9', $this->transport->getRequests()[0]->getUrl());
    }

    public function testALogLongerThanTheContractIsCutToItsEnd(): void
    {
        $this->client()->pulse('job', ['log' => str_repeat('a', 70000).'END']);

        $log = $this->transport->getRequests()[0]->getJson()['log'];
        self::assertSame(65536, strlen($log));
        self::assertSame('END', substr($log, -3));
    }

    public function testACutLogStartsAtAWholeCharacter(): void
    {
        $this->client()->pulse('job', ['log' => str_repeat('é', 40000)]);

        $log = $this->transport->getRequests()[0]->getJson()['log'];
        self::assertLessThanOrEqual(65536, strlen($log));
        self::assertSame(0, strlen($log) % 2);
        self::assertSame(1, preg_match('//u', $log));
    }

    public function testTheBodyStaysWithinWhatSonarTakesEvenWhenTheLogEscapesBadly(): void
    {
        $this->client()->pulse('job', ['log' => str_repeat("\"\n", 40000)]);

        $request = $this->transport->getRequests()[0];
        self::assertLessThanOrEqual(80 * 1024, strlen($request->getBody()));
        self::assertNotSame('', $request->getJson()['log']);
    }

    public function testInvalidOptionalFieldsAreLeftOutWithOneLogLineEachAndThePulseIsStillSent(): void
    {
        $values = [];
        for ($i = 0; $i < 51; ++$i) {
            $values['v'.$i] = $i;
        }

        $result = $this->client()->pulse('job', ['count' => -3, 'duration' => -1.5, 'values' => $values, 'log' => 'kept']);

        self::assertTrue($result->isSent());
        self::assertSame(['id', 'ranAt', 'status', 'log'], array_keys($this->transport->getRequests()[0]->getJson()));
        $log = $this->capturedErrorLog();
        foreach (['count', 'duration', 'values'] as $field) {
            self::assertSame(1, substr_count($log, 'left out the invalid "'.$field.'"'), $field);
        }
    }

    public function testValuesThatAreNotScalarOrTooLongAreLeftOutAsAWhole(): void
    {
        $this->client()->pulse('job', ['values' => ['nested' => ['a']]]);
        $this->client()->pulse('job', ['values' => ['long' => str_repeat('x', 501)]]);

        foreach ($this->transport->getRequests() as $request) {
            self::assertArrayNotHasKey('values', $request->getJson());
        }
    }

    public function testAnUnknownStatusIsRejectedLocallyAndNothingIsSent(): void
    {
        $result = $this->client()->pulse('job', ['status' => 'bogus']);

        self::assertTrue($result->isRejected());
        self::assertStringContainsString('ok, warn, fail', (string) $result->getMessage());
        self::assertSame([], $this->transport->getRequests());
        self::assertSame([], $this->bufferFiles());
    }

    public function testACheckNameThatCannotBeOneSegmentIsRejectedLocally(): void
    {
        foreach (['', 'a/b'] as $name) {
            self::assertTrue($this->client()->pulse($name)->isRejected(), $name);
        }
        self::assertSame([], $this->transport->getRequests());
    }

    public function testTemporaryAnswersAlsoIncludeTimeoutTooEarlyAndRedirects(): void
    {
        foreach ([408, 425, 301, 302, 307] as $status) {
            $transport = new InMemoryTransport();
            $transport->respondWith($status);

            $result = $this->client(['transport' => $transport, 'bufferDir' => $this->dir.'/buffer-'.$status])->pulse('job');

            self::assertTrue($result->isBuffered(), (string) $status);
        }
    }

    public function testRetryAfterOfA429HoldsBackSendingAndFlushingUntilItIsOver(): void
    {
        $client = $this->client();
        $this->transport->respondWith(429, '', ['Retry-After' => '120']);
        self::assertTrue($client->pulse('job')->isBuffered());
        self::assertCount(1, $this->transport->getRequests());

        $this->clock->advance(60);
        $held = $client->pulse('job');

        self::assertTrue($held->isBuffered());
        self::assertCount(1, $this->transport->getRequests(), 'nothing was sent while sonar asked to wait');
        self::assertCount(2, $this->bufferFiles());

        $this->clock->advance(61);
        self::assertTrue($client->pulse('job')->isSent());
        self::assertCount(4, $this->transport->getRequests(), 'both buffered Pulses and the new one');
    }

    public function testRetryAfterOfA503IsHonouredToo(): void
    {
        $this->transport->respondWith(503, '', ['Retry-After' => '30']);
        $client = $this->client();
        $client->pulse('job');

        $client->pulse('job');

        self::assertCount(1, $this->transport->getRequests());
    }

    public function testAcceptedWithPendingRegistrationCountsAsSent(): void
    {
        $this->transport->respondWith(202, '{"status":"pending"}');

        $result = $this->client()->pulse('brand-new-job');

        self::assertTrue($result->isSent());
        $this->transport->respondWith(202);
        $this->client()->pulse('brand-new-job');
        self::assertCount(2, $this->transport->getRequests(), 'the first Pulse was not resent');
    }

    public function testAPulseRejectedForGoodIsDroppedAndLoggedWithoutTheToken(): void
    {
        foreach ([401, 403, 404, 422] as $status) {
            $this->transport->respondWith($status, '{"title":"Invented problem '.$status.'"}');

            $result = $this->client()->pulse('job');

            self::assertTrue($result->isRejected(), (string) $status);
            self::assertSame($status, $result->getStatusCode());
            self::assertSame([], $this->bufferFiles(), (string) $status);
        }
        $log = $this->capturedErrorLog();
        foreach ([401, 403, 404, 422] as $status) {
            self::assertStringContainsString($status.' Invented problem '.$status, $log);
        }
        self::assertSame(4, substr_count($log, 'sonar-php'));
        self::assertStringNotContainsString(self::TOKEN, $log);
    }

    public function testOtherClientErrorsAreRejectedToo(): void
    {
        foreach ([400, 409, 413] as $status) {
            $this->transport->respondWith($status);

            self::assertTrue($this->client()->pulse('job')->isRejected(), (string) $status);
        }
        self::assertSame([], $this->bufferFiles());
    }

    public function testTemporaryProblemsAreBufferedAndResentWithTheSameIdAndRanAt(): void
    {
        foreach ([503, 500, 429, 'network'] as $status) {
            $transport = new InMemoryTransport();
            'network' === $status ? $transport->failWith('Connection refused') : $transport->respondWith($status);
            $client = $this->client(['transport' => $transport, 'bufferDir' => $this->dir.'/buffer-'.$status]);

            $first = $client->pulse('job');

            self::assertTrue($first->isBuffered(), (string) $status);
            self::assertCount(1, $this->bufferFiles($this->dir.'/buffer-'.$status), (string) $status);
            $firstBody = $transport->getRequests()[0]->getJson();

            $this->clock->advance(60);
            $second = $client->pulse('job');

            self::assertTrue($second->isSent(), (string) $status);
            $requests = $transport->getRequests();
            self::assertCount(3, $requests, (string) $status);
            self::assertSame($firstBody, $requests[1]->getJson(), (string) $status);
            self::assertSame($second->getId(), $requests[2]->getJson()['id']);
            self::assertSame([], $this->bufferFiles($this->dir.'/buffer-'.$status), (string) $status);
        }
    }

    public function testBufferedPulsesAreSentOldestFirstBeforeTheNewOne(): void
    {
        $client = $this->client();
        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $this->clock->advance(60);
            $this->transport->failWith();
            $ids[] = $client->pulse('job-'.$i)->getId();
        }
        $before = count($this->transport->getRequests());
        self::assertCount(3, $this->bufferFiles());

        $this->clock->advance(60);
        $new = $client->pulse('job-new');

        $sent = array_slice($this->transport->getRequests(), $before);
        self::assertSame(
            [$ids[0], $ids[1], $ids[2], $new->getId()],
            array_map(function ($request) {
                return $request->getJson()['id'];
            }, $sent)
        );
        self::assertSame(
            ['job-0', 'job-1', 'job-2', 'job-new'],
            array_map(function ($request) {
                return substr($request->getUrl(), strrpos($request->getUrl(), '/') + 1);
            }, $sent)
        );
        self::assertSame('2026-11-18T10:01:00.000000+00:00', $sent[0]->getJson()['ranAt']);
        self::assertSame('2026-11-18T10:03:00.000000+00:00', $sent[2]->getJson()['ranAt']);
    }
}
