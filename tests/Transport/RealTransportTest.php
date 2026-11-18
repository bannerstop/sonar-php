<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests\Transport;

use Bannerstop\Sonar\Tests\Support\StubServer;
use Bannerstop\Sonar\Transport\CurlTransport;
use Bannerstop\Sonar\Transport\StreamTransport;
use Bannerstop\Sonar\Transport\Transport;
use Bannerstop\Sonar\Transport\TransportException;
use PHPUnit\Framework\TestCase;

/**
 * CurlTransport and StreamTransport against a local stub HTTP server.
 */
final class RealTransportTest extends TestCase
{
    /** @var StubServer */
    private $server;

    protected function setUp(): void
    {
        $this->server = new StubServer();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    /**
     * @return array<string, Transport>
     */
    private function transports(float $timeout = 5.0): array
    {
        $transports = ['stream' => new StreamTransport($timeout)];
        if (extension_loaded('curl')) {
            $transports['curl'] = new CurlTransport(2.0, $timeout);
        }

        return $transports;
    }

    public function testItPostsTheBodyWithTheHeadersToThePath(): void
    {
        $this->server->start();

        foreach ($this->transports() as $name => $transport) {
            $this->server->forgetRequests();

            $response = $transport->send($this->server->getUrl().'/intake/v1/pulse/nightly%20job', [
                'Authorization' => 'Bearer invented',
                'Content-Type' => 'application/json',
            ], '{"id":"x"}');

            $requests = $this->server->getRequests();
            self::assertCount(1, $requests, $name);
            self::assertSame('POST', $requests[0]['method'], $name);
            self::assertSame('/intake/v1/pulse/nightly%20job', $requests[0]['uri'], $name);
            self::assertSame('Bearer invented', $requests[0]['headers']['Authorization'], $name);
            self::assertSame('application/json', $requests[0]['headers']['Content-Type'], $name);
            self::assertSame('{"id":"x"}', $requests[0]['body'], $name);
            self::assertSame(202, $response->getStatusCode(), $name);
            self::assertSame('{"status":"accepted"}', $response->getBody(), $name);
        }
    }

    public function testEveryStatusIsAnAnswerNotAnException(): void
    {
        $this->server->start();

        foreach ($this->transports() as $name => $transport) {
            foreach ([401, 422, 429, 503] as $status) {
                $this->server->answer((string) $status);

                $response = $transport->send($this->server->getUrl().'/x', [], '{}');

                self::assertSame($status, $response->getStatusCode(), $name);
                self::assertSame('{"title":"Stub answered '.$status.'"}', $response->getBody(), $name);
            }
        }
    }

    public function testAConnectionRefusedIsATransportException(): void
    {
        foreach ($this->transports() as $name => $transport) {
            try {
                $transport->send($this->server->getUrl().'/x', [], '{}');
                self::fail($name.' did not throw');
            } catch (TransportException $e) {
                self::assertNotSame('', $e->getMessage(), $name);
            }
        }
    }

    public function testATimeoutIsATransportExceptionAfterAboutTheTimeout(): void
    {
        $this->server->start();
        $this->server->answer('sleep:2500:202');

        foreach ($this->transports(0.5) as $name => $transport) {
            $started = microtime(true);
            try {
                $transport->send($this->server->getUrl().'/x', [], '{}');
                self::fail($name.' did not throw');
            } catch (TransportException $e) {
                self::assertLessThan(2.0, microtime(true) - $started, $name);
            }
        }
    }
}
