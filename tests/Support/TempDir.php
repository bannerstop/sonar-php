<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests\Support;

final class TempDir
{
    public static function create(): string
    {
        $path = sys_get_temp_dir().'/sonar-php-test-'.bin2hex(random_bytes(6));
        mkdir($path, 0700, true);

        return $path;
    }

    public static function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
            self::remove($path.'/'.$name);
        }
        rmdir($path);
    }
}
