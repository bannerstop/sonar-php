<?php

declare(strict_types=1);

namespace Bannerstop\Sonar;

/**
 * @internal
 */
final class Json
{
    /**
     * @param mixed $value
     *
     * @throws \RuntimeException
     */
    public static function encode($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (false === $json) {
            throw new \RuntimeException('The Pulse cannot be encoded as JSON: '.json_last_error_msg());
        }

        return $json;
    }
}
