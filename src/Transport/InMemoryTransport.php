<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Transport;

/**
 * A transport for your own tests: it records every request and answers with what you programmed, in order. When
 * the programmed answers are used up it answers 202. Nothing leaves the process.
 *
 *     $transport = new InMemoryTransport();
 *     $transport->respondWith(503)->failWith('connection refused');
 *     // ... run the job ...
 *     self::assertCount(1, $transport->getRequests());
 */
final class InMemoryTransport implements Transport
{
    /** @var list<RecordedRequest> */
    private $requests = [];

    /** @var list<TransportResponse|TransportException> */
    private $queue = [];

    /**
     * The next answer, after those already programmed.
     *
     * @param array<string, string> $headers
     *
     * @return $this
     */
    public function respondWith(int $status, string $body = '', array $headers = []): self
    {
        $this->queue[] = new TransportResponse($status, $body, $headers);

        return $this;
    }

    /**
     * The next request fails without an answer, like a refused connection or a timeout.
     *
     * @return $this
     */
    public function failWith(string $message = 'connection refused'): self
    {
        $this->queue[] = new TransportException($message);

        return $this;
    }

    /**
     * @return list<RecordedRequest>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    public function send(string $url, array $headers, string $body): TransportResponse
    {
        $this->requests[] = new RecordedRequest($url, $headers, $body);
        $next = array_shift($this->queue);
        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next ?? new TransportResponse(202, '{"status":"accepted"}');
    }
}
