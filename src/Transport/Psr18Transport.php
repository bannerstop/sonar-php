<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Transport;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends through a PSR-18 client, so the framework's HTTP stack, proxy settings and profiler are used. Needs
 * psr/http-client and PSR-17 factories (psr/http-factory), which this package only suggests. Timeouts are the
 * client's own: configure them there (2 s to connect and 5 s in total are the defaults of the other transports).
 */
final class Psr18Transport implements Transport
{
    /** @var ClientInterface */
    private $client;

    /** @var RequestFactoryInterface */
    private $requests;

    /** @var StreamFactoryInterface */
    private $streams;

    public function __construct(ClientInterface $client, RequestFactoryInterface $requests, StreamFactoryInterface $streams)
    {
        $this->client = $client;
        $this->requests = $requests;
        $this->streams = $streams;
    }

    public function send(string $url, array $headers, string $body): TransportResponse
    {
        $request = $this->requests->createRequest('POST', $url)->withBody($this->streams->createStream($body));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        $headers = [];
        foreach (array_keys($response->getHeaders()) as $name) {
            $headers[(string) $name] = $response->getHeaderLine((string) $name);
        }

        return new TransportResponse($response->getStatusCode(), (string) $response->getBody(), $headers);
    }
}
