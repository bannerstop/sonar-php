<?php

declare(strict_types=1);

namespace Bannerstop\Sonar;

/**
 * The statuses of a Pulse.
 */
final class Status
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /**
     * @param mixed $status
     */
    public static function isValid($status): bool
    {
        return in_array($status, [self::OK, self::WARN, self::FAIL], true);
    }
}
