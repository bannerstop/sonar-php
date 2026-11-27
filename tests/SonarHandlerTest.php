<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests;

use Bannerstop\Sonar\ErrorReporter;
use Bannerstop\Sonar\Monolog\SonarHandler;
use Bannerstop\Sonar\Tests\Support\FakeClock;
use Bannerstop\Sonar\Tests\Support\TempDir;
use Bannerstop\Sonar\Transport\InMemoryTransport;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The Monolog handler through a real Logger, so it is tested with whatever Monolog major is installed.
 */
final class SonarHandlerTest extends TestCase
{
    /** @var string */
    private $dir;

    /** @var InMemoryTransport */
    private $transport;

    protected function setUp(): void
    {
        $this->dir = TempDir::create();
        $this->transport = new InMemoryTransport();
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    /**
     * @param int|string|null $level
     */
    private function logger($level = null): Logger
    {
        $reporter = new ErrorReporter('https://sonar.example.com', 'sonar_pls_test', 'production', [
            'transport' => $this->transport,
            'bufferDir' => $this->dir.'/buffer',
            'clock' => new FakeClock(),
            'sapi' => 'cli',
            'server' => [],
            'serverName' => 'web-1',
            'maxItems' => 50,
        ]);

        return new Logger('app', [null === $level ? new SonarHandler($reporter) : new SonarHandler($reporter, $level)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(int $n = 0): array
    {
        return json_decode($this->transport->getRequests()[$n]->getBody(), true);
    }

    public function testRecordsBelowWarningAreIgnoredByDefault(): void
    {
        $logger = $this->logger();

        $logger->debug('d');
        $logger->info('i');
        $logger->notice('n');

        self::assertCount(0, $this->transport->getRequests());
    }

    public function testAWarningIsALogItemWithChannelAndContext(): void
    {
        $this->logger()->warning('Stock is low', ['sku' => 'A-1', 'password' => 'hunter2']);

        $body = $this->body();
        self::assertSame('log', $body['type']);
        self::assertSame('warning', $body['level']);
        self::assertSame('Stock is low', $body['message']);
        self::assertSame('app', $body['context']['channel']);
        self::assertSame('A-1', $body['context']['sku']);
        self::assertSame('[scrubbed]', $body['context']['password']);
    }

    public function testAnExceptionInTheContextMakesAnErrorItem(): void
    {
        $this->logger()->error('Checkout failed', ['exception' => new \RuntimeException('provider down'), 'order' => 7]);

        $body = $this->body();
        self::assertSame('error', $body['type']);
        self::assertSame('RuntimeException', $body['exception']['class']);
        self::assertSame('provider down', $body['exception']['message']);
        self::assertSame('Checkout failed', $body['context']['logMessage']);
        self::assertSame(7, $body['context']['order']);
        self::assertArrayNotHasKey('exception', $body['context']);
    }

    public function testAnExceptionBelowErrorStaysALogItemWithItsClass(): void
    {
        $this->logger()->warning('Retrying', ['exception' => new \RuntimeException('timeout')]);

        $body = $this->body();
        self::assertSame('log', $body['type']);
        self::assertSame('RuntimeException', $body['context']['exception']['class']);
        self::assertSame('timeout', $body['context']['exception']['message']);
    }

    public function testCriticalAlertAndEmergencyAreCriticalForSonar(): void
    {
        $logger = $this->logger();

        $logger->critical('c');
        $logger->alert('a');
        $logger->emergency('e');

        foreach ([0, 1, 2] as $n) {
            self::assertSame('critical', $this->body($n)['level']);
        }
    }

    public function testTheThresholdCanBeAMonologLevelNumberOrName(): void
    {
        $byNumber = $this->logger(Logger::ERROR);
        $byNumber->warning('below');
        $byNumber->error('at');
        $byName = $this->logger('debug');
        $byName->debug('lowest');

        self::assertCount(2, $this->transport->getRequests());
        self::assertSame('at', $this->body(0)['message']);
        self::assertSame('debug', $this->body(1)['level']);
    }

    public function testTheFingerprintOfTheContextIsPassedOn(): void
    {
        $this->logger()->error('x', ['fingerprint' => 'my-key']);

        self::assertSame('my-key', $this->body()['fingerprint']);
    }

    public function testASonarOutageNeverReachesTheLogger(): void
    {
        $this->transport->failWith('connection refused');

        $this->logger()->error('x', ['exception' => new \RuntimeException('y')]);

        $this->addToAssertionCount(1);
    }

    public function testAnObjectInTheContextIsNotDumped(): void
    {
        $this->logger()->error('x', ['cart' => new \ArrayObject(['card' => '4111111111111111'])]);

        self::assertSame('[object ArrayObject]', $this->body()['context']['cart']);
    }
}
