<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests;

use Bannerstop\Sonar\SonarClient;
use Bannerstop\Sonar\Tests\Support\Process;
use Bannerstop\Sonar\Tests\Support\StubServer;
use Bannerstop\Sonar\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * bin/sonar-flush, run as a process with the configuration in the environment.
 */
final class FlushCommandTest extends TestCase
{
    /** @var StubServer */
    private $server;

    /** @var string */
    private $token;

    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->server = new StubServer();
        $this->server->start();
        $this->token = 'token-'.bin2hex(random_bytes(4));
        $this->dir = TempDir::create();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        TempDir::remove($this->dir);
    }

    /**
     * @return array{int, string} exit code and output
     */
    private function runCommand(): array
    {
        return (new Process([PHP_BINARY, dirname(__DIR__).'/bin/sonar-flush'], [
            'SONAR_URL' => $this->server->getUrl(),
            'SONAR_TOKEN' => $this->token,
            'SONAR_BUFFER_DIR' => $this->dir.'/buffer',
        ]))->wait();
    }

    public function testItResendsTheBufferOfTheClientFromTheEnvironment(): void
    {
        $this->server->answer('503');
        $client = new SonarClient($this->server->getUrl(), $this->token, ['bufferDir' => $this->dir.'/buffer']);
        $first = $client->pulse('job');
        $second = $client->pulse('job');
        $this->server->forgetRequests();
        $this->server->answer('202');

        list($exit, $output) = $this->runCommand();

        self::assertSame(0, $exit);
        self::assertStringContainsString('sent 2, dropped 0, 0 left', $output);
        $ids = array_map(function ($request) {
            return json_decode($request['body'], true)['id'];
        }, $this->server->getRequests());
        self::assertSame([$first->getId(), $second->getId()], $ids);
    }

    public function testItExitsWithZeroEvenWhenSonarIsDown(): void
    {
        $this->server->answer('503');
        (new SonarClient($this->server->getUrl(), $this->token, ['bufferDir' => $this->dir.'/buffer']))->pulse('job');

        list($exit, $output) = $this->runCommand();

        self::assertSame(0, $exit);
        self::assertStringContainsString('sent 0, dropped 0, 1 left', $output);
        self::assertStringContainsString('could not be reached', $output);
    }
}
