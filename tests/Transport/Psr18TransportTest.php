<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests\Transport;

use Bannerstop\Sonar\Transport\Psr18Transport;
use Bannerstop\Sonar\Transport\TransportException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class Psr18TransportTest extends TestCase
{
    public function testItSendsThePostRequestThroughTheClientAndMapsTheResponse(): void
    {
        $client = new class () implements ClientInterface {
            /** @var RequestInterface|null */
            public $request;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new Response(422, ['Retry-After' => '30'], '{"title":"Invented"}');
            }
        };
        $factory = new Psr17Factory();

        $response = (new Psr18Transport($client, $factory, $factory))->send(
            'https://sonar.example.com/intake/v1/pulse/job',
            ['Authorization' => 'Bearer invented', 'Content-Type' => 'application/json'],
            '{"id":"x"}'
        );

        self::assertSame('POST', $client->request->getMethod());
        self::assertSame('https://sonar.example.com/intake/v1/pulse/job', (string) $client->request->getUri());
        self::assertSame('Bearer invented', $client->request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $client->request->getHeaderLine('Content-Type'));
        self::assertSame('{"id":"x"}', (string) $client->request->getBody());
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('30', $response->getHeader('Retry-After'));
        self::assertSame('{"title":"Invented"}', $response->getBody());
    }

    public function testAClientExceptionIsATransportException(): void
    {
        $client = new class () implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {
                };
            }
        };
        $factory = new Psr17Factory();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('connection refused');

        (new Psr18Transport($client, $factory, $factory))->send('https://sonar.example.com/x', [], '{}');
    }
}
