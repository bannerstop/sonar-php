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

    /** @var bool */
    private static $loggedNotConfigured = false;

    /** @var ErrorReporter|null */
    private static $errorReporter;

    /**
     * Builds the client explicitly. Without a URL or token the environment variables SONAR_URL and SONAR_TOKEN are
     * used; SONAR_BUFFER_DIR sets the `bufferDir` option when the options do not.
     *
     * @param array<string, mixed> $options see SonarClient
     *
     * @throws \InvalidArgumentException when URL or token are missing everywhere, or an option is unknown
     */
    public static function configure(?string $url = null, ?string $token = null, array $options = []): void
    {
        $bufferDir = getenv('SONAR_BUFFER_DIR');
        if (!isset($options['bufferDir']) && false !== $bufferDir && '' !== $bufferDir) {
            $options['bufferDir'] = $bufferDir;
        }
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

    /**
     * Tells sonar which PHP this application runs with, when it is due (see RuntimeReporter). Call it on every
     * request or command, as early as the bootstrap allows. Reads SONAR_URL, SONAR_TOKEN and the Service's slug
     * from the argument or SONAR_SERVICE. Never throws; with none of them set it does nothing, with some missing it logs once per process.
     *
     * @param array<string, mixed> $options see RuntimeReporter
     */
    public static function reportRuntime(?string $service = null, array $options = []): void
    {
        try {
            $service = $service ?? getenv('SONAR_SERVICE');
            if ((false === $service || '' === $service) && false === getenv('SONAR_URL') && false === getenv('SONAR_TOKEN')) {
                return; // not set up: an environment without sonar (a developer's machine) stays quiet
            }
            $problem = 'SONAR_URL, SONAR_TOKEN and SONAR_SERVICE are needed to report the runtime.';
            if (false === $service || '' === trim($service)) {
                throw new \InvalidArgumentException($problem);
            }
            (new RuntimeReporter(self::env('SONAR_URL', $problem), self::env('SONAR_TOKEN', $problem), $service, $options))->reportIfDue();
        } catch (\Throwable $e) {
            if (!self::$loggedNotConfigured) {
                self::$loggedNotConfigured = true;
                Log::error($e->getMessage());
            }
        }
    }

    /**
     * Registers the handlers that report uncaught exceptions, PHP errors and fatal errors to sonar (see
     * ErrorReporter). Call it early in the bootstrap, once. Reads SONAR_URL, SONAR_TOKEN and the environment from
     * SONAR_ENVIRONMENT (or APP_ENV); SONAR_RELEASE is the code version unless `codeVersion` is an option. Never
     * throws; with neither SONAR_URL nor SONAR_TOKEN set it does nothing, with other things missing it logs once.
     *
     * @param array<string, mixed> $options see ErrorReporter
     */
    public static function registerErrorHandler(array $options = []): ?ErrorReporter
    {
        $reporter = self::errorReporter($options);
        if (null !== $reporter) {
            try {
                $reporter->register();
            } catch (\Throwable $e) {
                Log::error($e->getMessage());
            }
        }

        return $reporter;
    }

    /**
     * Reports an exception you caught and handled, with extra context. Never throws.
     *
     * @param array<string, mixed> $context a `fingerprint` is your own grouping key
     */
    public static function reportException(\Throwable $e, array $context = []): void
    {
        $reporter = self::errorReporter();
        if (null !== $reporter) {
            $reporter->reportThrowable($e, $context);
        }
    }

    /**
     * Reports a message. Never throws.
     *
     * @param string               $level   debug, info, notice, warning, error or critical
     * @param array<string, mixed> $context
     */
    public static function reportMessage(string $level, string $message, array $context = []): void
    {
        $reporter = self::errorReporter();
        if (null !== $reporter) {
            $reporter->reportMessage($level, $message, $context);
        }
    }

    /**
     * The reporter the facade uses (built from the environment on first use), for a Monolog `SonarHandler`.
     * Null when sonar is not configured.
     *
     * @param array<string, mixed> $options see ErrorReporter, used only when the reporter is built
     */
    public static function errorReporter(array $options = []): ?ErrorReporter
    {
        if (null !== self::$errorReporter) {
            return self::$errorReporter;
        }
        try {
            $url = getenv('SONAR_URL');
            $token = getenv('SONAR_TOKEN');
            if (false === $url && false === $token) {
                return null; // not set up: an environment without sonar (a developer's machine) stays quiet
            }
            $problem = 'SONAR_URL, SONAR_TOKEN and SONAR_ENVIRONMENT (or APP_ENV) are needed to report errors.';
            $environment = getenv('SONAR_ENVIRONMENT');
            if (false === $environment || '' === $environment) {
                $environment = getenv('APP_ENV');
            }
            if (false === $environment || '' === $environment) {
                throw new \InvalidArgumentException($problem);
            }
            $release = getenv('SONAR_RELEASE');
            if (!isset($options['codeVersion']) && false !== $release && '' !== $release) {
                $options['codeVersion'] = $release;
            }

            return self::$errorReporter = new ErrorReporter(self::env('SONAR_URL', $problem), self::env('SONAR_TOKEN', $problem), strtolower($environment), $options);
        } catch (\Throwable $e) {
            if (!self::$loggedNotConfigured) {
                self::$loggedNotConfigured = true;
                Log::error($e->getMessage());
            }

            return null;
        }
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
        if (null !== self::$errorReporter) {
            self::$errorReporter->unregister();
        }
        self::$errorReporter = null;
        self::$client = null;
        self::$loggedNotConfigured = false;
    }

    private static function tryClient(): ?SonarClient
    {
        if (null === self::$client) {
            try {
                self::configure();
            } catch (\Throwable $e) {
                if (!self::$loggedNotConfigured) {
                    self::$loggedNotConfigured = true;
                    Log::error($e->getMessage());
                }

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
