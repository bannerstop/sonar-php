<?php

declare(strict_types=1);

namespace Bannerstop\Sonar;

/**
 * A static entry point over one SonarClient, built on first use from SONAR_URL and SONAR_TOKEN or from
 * `Sonar::configure()`. Like the client it never throws into the job; without a configuration a Pulse is
 * rejected (and logged) and `run()` just runs the job.
 */
final class Sonar
{
    /** @var SonarClient|null */
    private static $client;

    /**
     * Builds the client explicitly. Without a URL or token the environment variables SONAR_URL and SONAR_TOKEN are used.
     *
     * @param array<string, mixed> $options see SonarClient
     *
     * @throws \InvalidArgumentException when URL or token are missing everywhere, or an option is unknown
     */
    public static function configure(?string $url = null, ?string $token = null, array $options = []): void
    {
        self::$client = new SonarClient(
            $url ?? self::env('SONAR_URL', 'SONAR_URL and SONAR_TOKEN are not set; configure the client or the environment.'),
            $token ?? self::env('SONAR_TOKEN', 'SONAR_URL and SONAR_TOKEN are not set; configure the client or the environment.'),
            $options
        );
    }

    /**
     * @param array<string, mixed> $fields see SonarClient::pulse()
     */
    public static function pulse(string $check, array $fields = []): PulseResult
    {
        $client = self::tryClient();
        if (null === $client) {
            return PulseResult::rejected(null, null, 'Dropped, sonar is not configured.');
        }

        return $client->pulse($check, $fields);
    }

    /**
     * @param callable(Run): mixed $job
     *
     * @return mixed what the job returned
     */
    public static function run(string $check, callable $job)
    {
        $client = self::tryClient();
        if (null === $client) {
            return $job(new Run());
        }

        return $client->run($check, $job);
    }

    public static function flush(int $maxPulses = PHP_INT_MAX, ?float $maxSeconds = null): ?FlushResult
    {
        $client = self::tryClient();

        return null === $client ? null : $client->flush($maxPulses, $maxSeconds);
    }

    /**
     * Forgets the client, so the next call builds it again.
     */
    public static function reset(): void
    {
        self::$client = null;
    }

    private static function tryClient(): ?SonarClient
    {
        if (null === self::$client) {
            try {
                self::configure();
            } catch (\Throwable $e) {
                Log::error($e->getMessage());

                return null;
            }
        }

        return self::$client;
    }

    private static function env(string $name, string $problem): string
    {
        $value = getenv($name);
        if (false === $value || '' === $value) {
            throw new \InvalidArgumentException($problem);
        }

        return $value;
    }
}
