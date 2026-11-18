<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests;

use Bannerstop\Sonar\SonarClient;
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

    /** @var list<string> */
    private $buffersBefore;

    protected function setUp(): void
    {
        $this->server = new StubServer();
        $this->server->start();
        $this->token = 'token-'.bin2hex(random_bytes(4));
        $this->buffersBefore = glob(sys_get_temp_dir().'/sonar-php/*', GLOB_ONLYDIR) ?: [];
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        foreach (array_diff(glob(sys_get_temp_dir().'/sonar-php/*', GLOB_ONLYDIR) ?: [], $this->buffersBefore) as $buffer) {
            TempDir::remove($buffer);
        }
    }

    /**
     * @return array{int, string} exit code and output
     */
    private function runCommand(): array
    {
        $autoload = dirname((string) (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2).'/autoload.php';
        $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-d', 'auto_prepend_file='.$autoload, dirname(__DIR__).'/bin/sonar-flush']));
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), [
            'SONAR_URL' => $this->server->getUrl(),
            'SONAR_TOKEN' => $this->token,
        ]));
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);

        return [proc_close($process), $output];
    }

    public function testItResendsTheBufferOfTheClientFromTheEnvironment(): void
    {
        $this->server->answer('503');
        $client = new SonarClient($this->server->getUrl(), $this->token);
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
        (new SonarClient($this->server->getUrl(), $this->token))->pulse('job');

        list($exit, $output) = $this->runCommand();

        self::assertSame(0, $exit);
        self::assertStringContainsString('sent 0, dropped 0, 1 left', $output);
        self::assertStringContainsString('could not be reached', $output);
    }
}
