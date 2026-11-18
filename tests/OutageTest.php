<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests;

use Bannerstop\Sonar\SonarClient;
use Bannerstop\Sonar\Tests\Support\Process;
use Bannerstop\Sonar\Tests\Support\StubServer;
use Bannerstop\Sonar\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * The whole client with its default transport against a stub server that is down, failing and up again.
 */
final class OutageTest extends TestCase
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

    private function client(): SonarClient
    {
        return new SonarClient($this->server->getUrl(), 'invented-token', [
            'bufferDir' => $this->dir.'/buffer',
            'connectTimeout' => 0.5,
            'timeout' => 1.0,
        ]);
    }

    public function testPulsesBufferedDuringAnOutageArriveInOrderWithTheirOriginalRanAtAndId(): void
    {
        $client = $this->client();
        $sent = [];

        // sonar is down: connection refused
        $sent[] = $client->pulse('job', ['count' => 1]);
        // sonar is back but failing
        $this->server->start();
        $this->server->answer('503');
        $sent[] = $client->pulse('job', ['count' => 2]);
        $sent[] = $client->pulse('job', ['count' => 3]);
        foreach ($sent as $result) {
            self::assertTrue($result->isBuffered());
        }
        $this->server->forgetRequests();

        // sonar is healthy again
        $this->server->answer('202');
        $new = $client->pulse('job', ['count' => 4]);

        self::assertTrue($new->isSent());
        $requests = $this->server->getRequests();
        self::assertCount(4, $requests);
        $bodies = array_map(function ($request) {
            return json_decode($request['body'], true);
        }, $requests);
        self::assertSame([1, 2, 3, 4], array_column($bodies, 'count'));
        self::assertSame([$sent[0]->getId(), $sent[1]->getId(), $sent[2]->getId(), $new->getId()], array_column($bodies, 'id'));
        $ranAt = array_column($bodies, 'ranAt');
        $sorted = $ranAt;
        sort($sorted);
        self::assertSame($sorted, $ranAt, 'ranAt keeps the order of the runs');
        self::assertSame([], glob($this->dir.'/buffer/*.json'));
    }

    public function testTwoProcessesFlushingTheSameBufferNeverSendAnEntryTwice(): void
    {
        $this->server->start();
        $this->server->answer('503');
        $client = $this->client();
        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($client->pulse('job', ['count' => $i])->isBuffered());
        }
        $this->server->forgetRequests();
        $this->server->answer('sleep:400:202'); // holding the lock for two seconds

        $command = [PHP_BINARY, __DIR__.'/Support/flush-worker.php', $this->server->getUrl(), $this->dir.'/buffer'];
        $first = new Process($command);
        // the second flush starts once the first has sent something, i.e. while it holds the lock
        $deadline = microtime(true) + 20;
        while ([] === $this->server->getRequests()) {
            self::assertLessThan($deadline, microtime(true), 'the first flush did not start sending');
            usleep(20000);
        }
        $second = new Process($command);
        $reports = [json_decode($first->wait()[1], true), json_decode($second->wait()[1], true)];

        $ids = array_map(function ($request) {
            return json_decode($request['body'], true)['id'];
        }, $this->server->getRequests());
        self::assertCount(5, $ids);
        self::assertSame($ids, array_values(array_unique($ids)), 'no entry was sent twice');
        self::assertSame(5, $reports[0]['sent'] + $reports[1]['sent']);
        self::assertSame(5, $reports[0]['sent']);
        self::assertTrue($reports[1]['skipped'], 'the second flush saw the lock and skipped');
        self::assertSame([], glob($this->dir.'/buffer/*.json'));
    }
}
