<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Transport;

/**
 * Sends one POST request. A transport owns its timeouts; it never follows redirects and it does not judge the
 * status code: every HTTP answer is a TransportResponse, only a missing answer is a TransportException.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers header name => value
     *
     * @throws TransportException when no HTTP answer came back (connection refused, timeout, TLS error, ...)
     */
    public function send(string $url, array $headers, string $body): TransportResponse;
}
