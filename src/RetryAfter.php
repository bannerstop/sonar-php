<?php

declare(strict_types=1);

namespace Bannerstop\Sonar;

/**
 * @internal
 */
final class RetryAfter
{
    /**
     * How long a `Retry-After` header (seconds or an HTTP date) asks to wait, at most `$max` seconds; 0 when there
     * is no usable header.
     */
    public static function seconds(?string $header, float $now, float $max): float
    {
        if (null === $header || '' === trim($header)) {
            return 0.0;
        }
        $header = trim($header);
        if (ctype_digit($header)) {
            $wait = (float) $header;
        } else {
            $date = strtotime($header);
            $wait = false === $date ? 0.0 : $date - $now;
        }

        return $wait <= 0 ? 0.0 : min($wait, $max);
    }
}
