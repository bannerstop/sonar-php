<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests\Support;

/**
 * The invented secrets of tests/Fixtures/secrets-golden.json, the same file sonar's tests read. Nothing in it is real.
 */
final class SecretsFixture
{
    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/secrets-golden.json'), true);
        if (!is_array($data)) {
            throw new \RuntimeException('The secrets fixture is not valid JSON.');
        }

        return $data;
    }

    /**
     * @return list<string> names whose value has to be scrubbed
     */
    public static function scrubbedNames(): array
    {
        return self::all()['names']['scrubbed'];
    }

    /**
     * @return list<string> names whose value is kept
     */
    public static function keptNames(): array
    {
        return self::all()['names']['kept'];
    }

    /**
     * @return array<string, string|int> the invented environment bag: secrets and harmless entries together, as $_SERVER holds them
     */
    public static function bag(): array
    {
        $bag = self::all()['bag'];

        return $bag['secret'] + $bag['harmless'];
    }

    /**
     * @return array<string, string>
     */
    public static function secretBag(): array
    {
        return self::all()['bag']['secret'];
    }

    /**
     * @return list<array{label: string, text: string, leak: string}>
     */
    public static function texts(): array
    {
        return self::all()['texts'];
    }

    /**
     * @return list<string> what must never appear in anything sent
     */
    public static function leaks(): array
    {
        return self::all()['leaks'];
    }

    /**
     * @return list<string> harmless values that must survive
     */
    public static function keep(): array
    {
        return self::all()['keep'];
    }
}
