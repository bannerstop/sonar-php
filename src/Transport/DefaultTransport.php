<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Transport;

/**
 * @internal
 */
final class DefaultTransport
{
    public static function create(float $connectTimeout, float $timeout): Transport
    {
        return extension_loaded('curl') ? new CurlTransport($connectTimeout, $timeout) : new StreamTransport($timeout);
    }
}
