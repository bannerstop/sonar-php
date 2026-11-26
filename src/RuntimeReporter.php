<?php

declare(strict_types=1);

namespace Bannerstop\Sonar;

use Bannerstop\Sonar\Transport\DefaultTransport;
use Bannerstop\Sonar\Transport\Transport;

/**
 * Tells sonar which PHP the application runs with (`POST /intake/v1/runtime`): the full PHP version, the SAPI
 * (web or cli) and the version of this package, for one Service. Meant to be called on every request or command:
 * a small marker file remembers what was reported, so all but the first call after a deploy and then one a day
 * per SAPI only read that file. In a web request the report is sent after the response has gone out, with a short
 * timeout. Nothing here throws, and a failure reaches the error log at most once a day.
 *
 * Options (all optional):
 *
 * - connectTimeout (float, 1.0) and timeout (float, 2.0): seconds, for the default transports
 * - transport (Transport): replaces the default; it owns its timeouts
 * - markerDir (string): defaults to sys_get_temp_dir()/sonar-php/runtime-<hash of url, token and service>
 * - release (string): anything that changes with a deploy; by default the directory of the installed package is
 *   used, which changes with every release directory
 * - sapi ("web" or "cli"): for tests; by default PHP_SAPI decides
 * - afterResponse (callable(callable $task, bool $web): void): schedules the send; for tests
 * - clock (callable returning DateTimeImmutable): for tests only
 */
final class RuntimeReporter
{
    public const PACKAGE_VERSION = '1.1.0';
    private const PERIOD = 86400;       // reported again after a day (sonar drops a value after 3 days)
    private const RETRY = 900;          // a failed report is tried again after 15 minutes
    private const LOG_EVERY = 86400;    // a failure is logged once a day
    private const OPTIONS = ['connectTimeout', 'timeout', 'transport', 'markerDir', 'release', 'sapi', 'afterResponse', 'clock'];

    /** @var string */
    private $url;

    /** @var string */
    private $token;

    /** @var string */
    private $service;

    /** @var Transport */
    private $transport;

    /** @var string */
    private $markerDir;

    /** @var string */
    private $release;

    /** @var string */
    private $sapi;

    /** @var callable(callable, bool): void */
    private $afterResponse;

    /** @var callable(): \DateTimeImmutable */
    private $clock;

    /**
     * @param string               $url     the base URL of sonar, e.g. https://sonar.example.com
     * @param string               $token   the token of the Project (`sonar_pls_...`)
     * @param string               $service the slug of the Service this application is
     * @param array<string, mixed> $options
     *
     * @throws \InvalidArgumentException for an empty URL, token or service and for an unknown option
     */
    public function __construct(string $url, string $token, string $service, array $options = [])
    {
        if ('' === trim($url) || '' === $token || '' === trim($service)) {
            throw new \InvalidArgumentException('The URL and token of sonar and the slug of the Service are needed.');
        }
        $unknown = array_diff(array_keys($options), self::OPTIONS);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(sprintf('Unknown option(s): %s.', implode(', ', $unknown)));
        }
        $this->url = rtrim(trim($url), '/');
        $this->token = $token;
        $this->service = trim($service);
        $this->sapi = $options['sapi'] ?? ('cli' === PHP_SAPI || 'phpdbg' === PHP_SAPI ? 'cli' : 'web');
        if (!in_array($this->sapi, ['web', 'cli'], true)) {
            throw new \InvalidArgumentException('The sapi option has to be "web" or "cli".');
        }
        $this->transport = $options['transport'] ?? DefaultTransport::create((float) ($options['connectTimeout'] ?? 1.0), (float) ($options['timeout'] ?? 2.0));
        $this->markerDir = $options['markerDir'] ?? sys_get_temp_dir().'/sonar-php/runtime-'.substr(hash('sha256', $this->url."\n".$this->token."\n".$this->service), 0, 16);
        $this->release = (string) ($options['release'] ?? dirname(__DIR__));
        $this->afterResponse = $options['afterResponse'] ?? [new AfterResponse(), 'defer'];
        $this->clock = $options['clock'] ?? static function (): \DateTimeImmutable {
            return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        };
    }

    /**
     * Reports when it is due (first run of a release, then once a day per SAPI), after the response in a web
     * request. When nothing is due it only reads the marker. Never throws.
     */
    public function reportIfDue(): void
    {
        try {
            if (!$this->isDue($this->readMarker())) {
                return;
            }
            call_user_func($this->afterResponse, function (): void {
                $this->report();
            }, 'web' === $this->sapi);
        } catch (\Throwable $e) {
            // monitoring never breaks the application
        }
    }

    private function report(): void
    {
        $marker = $this->readMarker();
        if (!$this->isDue($marker)) {
            return; // another process was faster
        }
        $now = $this->now();
        $marker['attemptedAt'] = $now;
        $marker['attemptedSignature'] = $this->signature();
        if (!$this->writeMarker($marker)) {
            return; // without a marker every request would report
        }
        try {
            $response = $this->transport->send($this->url.'/intake/v1/runtime', [
                'Authorization' => 'Bearer '.$this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], Json::encode([
                'service' => $this->service,
                'sapi' => $this->sapi,
                'php' => PHP_VERSION,
                'package' => self::PACKAGE_VERSION,
            ]));
        } catch (\Throwable $e) {
            $this->failed($marker, 'no answer from sonar: '.$e->getMessage());

            return;
        }
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            $marker['reportedAt'] = $now;
            $marker['reportedSignature'] = $this->signature();
            $this->writeMarker($marker);

            return;
        }
        $problem = json_decode($response->getBody());
        $title = $problem instanceof \stdClass && is_string($problem->title ?? null) ? substr($problem->title, 0, 200) : '';
        $this->failed($marker, trim(sprintf('sonar answered %d %s', $status, $title)));
    }

    /**
     * @param array<string, mixed> $marker
     */
    private function failed(array $marker, string $why): void
    {
        $now = $this->now();
        $loggedAt = $marker['loggedAt'] ?? null;
        if (!is_int($loggedAt) || $now - $loggedAt >= self::LOG_EVERY || $loggedAt > $now) {
            $marker['loggedAt'] = $now;
            if ($this->writeMarker($marker)) {
                Log::error(sprintf('The runtime report for "%s" (%s) failed: %s', $this->service, $this->sapi, $why));
            }
        }
    }

    /**
     * @param array<string, mixed> $marker
     */
    private function isDue(array $marker): bool
    {
        $now = $this->now();
        $reportedAt = $marker['reportedAt'] ?? null;
        if (($marker['reportedSignature'] ?? null) === $this->signature() && is_int($reportedAt) && $now >= $reportedAt && $now - $reportedAt < self::PERIOD) {
            return false;
        }
        $attemptedAt = $marker['attemptedAt'] ?? null;


        return !(($marker['attemptedSignature'] ?? null) === $this->signature() && is_int($attemptedAt) && $now >= $attemptedAt && $now - $attemptedAt < self::RETRY);
    }

    /**
     * What a deploy changes: the PHP version, this package, and the release (directory or option).
     */
    private function signature(): string
    {
        return hash('sha256', implode("\n", [PHP_VERSION, self::PACKAGE_VERSION, $this->release]));
    }

    /**
     * @return array<string, mixed>
     */
    private function readMarker(): array
    {
        $content = @file_get_contents($this->markerFile());
        $marker = false === $content ? null : json_decode($content, true);

        return is_array($marker) ? $marker : [];
    }

    /**
     * @param array<string, mixed> $marker
     */
    private function writeMarker(array $marker): bool
    {
        try {
            if (!is_dir($this->markerDir) && !@mkdir($this->markerDir, 0700, true) && !is_dir($this->markerDir)) {
                return false;
            }
            $temp = $this->markerDir.'/.'.bin2hex(random_bytes(6)).'.tmp';
            if (false === @file_put_contents($temp, Json::encode($marker)) || !@rename($temp, $this->markerFile())) {
                @unlink($temp);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function markerFile(): string
    {
        return $this->markerDir.'/'.$this->sapi.'.json';
    }

    private function now(): int
    {
        return call_user_func($this->clock)->getTimestamp();
    }
}
