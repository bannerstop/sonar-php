<?php

declare(strict_types=1);

namespace Bannerstop\Sonar;

/**
 * @internal
 */
final class Log
{
    public static function error(string $message): void
    {
        error_log('sonar-php: '.str_replace(["\r", "\n"], ' ', $message));
    }
}
