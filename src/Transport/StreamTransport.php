<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Transport;

/**
 * The fallback for PHP installs without ext-curl, built on the http stream wrapper (allow_url_fopen has to be on).
 * The wrapper has one timeout for connecting and for each read, so `timeout` bounds each of them, not the sum.
 */
final class StreamTransport implements Transport
{
    /** @var float */
    private $timeout;

    /**
     * @param float $timeout seconds for connecting and for each read
     */
    public function __construct(float $timeout = 5.0)
    {
        $this->timeout = $timeout;
    }

    public function send(string $url, array $headers, string $body): TransportResponse
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $lines),
            'content' => $body,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);

        $answer = @file_get_contents($url, false, $context);
        if (function_exists('http_get_last_response_headers')) {
            $responseHeaders = http_get_last_response_headers() ?? [];
        } else {
            // PHP before 8.4: file_get_contents() sets $http_response_header in the calling scope; read through
            // get_defined_vars() because PHP 8.5 deprecates naming the variable
            $responseHeaders = get_defined_vars()['http_response_header'] ?? []; // @phpstan-ignore nullCoalesce.offset
        }
        $status = 0;
        $headers = [];
        foreach ($responseHeaders as $line) {
            $parts = explode(':', $line, 2);
            if (2 === count($parts)) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
            if (1 === preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $match)) {
                $status = (int) $match[1]; // the last status line wins (after a 100 Continue)
            }
        }
        if (false === $answer || 0 === $status) {
            $error = error_get_last();

            throw new TransportException(null !== $error ? $error['message'] : 'No answer.');
        }

        return new TransportResponse($status, $answer, $headers);
    }
}
