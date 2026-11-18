<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Transport;

/**
 * The default transport when ext-curl is loaded.
 */
final class CurlTransport implements Transport
{
    /** @var float */
    private $connectTimeout;

    /** @var float */
    private $timeout;

    /**
     * @param float $connectTimeout seconds to connect
     * @param float $timeout        seconds for the whole request
     */
    public function __construct(float $connectTimeout = 2.0, float $timeout = 5.0)
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('ext-curl is not loaded; use StreamTransport instead.');
        }
        $this->connectTimeout = $connectTimeout;
        $this->timeout = $timeout;
    }

    public function send(string $url, array $headers, string $body): TransportResponse
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }
        $responseHeaders = [];
        $handle = curl_init($url);
        if (false === $handle) {
            throw new TransportException('curl could not be initialised.');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($this->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ceil($this->timeout * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (2 === count($parts)) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        $answer = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        if (PHP_VERSION_ID < 80000) {
            curl_close($handle); // a no-op since PHP 8.0, deprecated in 8.5
        }

        if (!is_string($answer) || 0 === $status) {
            throw new TransportException('' !== $error ? $error : 'No answer.');
        }

        return new TransportResponse($status, $answer, $responseHeaders);
    }
}
