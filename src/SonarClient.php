<?php

declare(strict_types=1);

namespace Bannerstop\Sonar;

use Bannerstop\Sonar\Transport\CurlTransport;
use Bannerstop\Sonar\Transport\StreamTransport;
use Bannerstop\Sonar\Transport\Transport;

/**
 * Sends Pulses to sonar. Nothing here throws into the job: a Pulse that cannot be delivered is buffered on disk
 * and resent later with its original `ranAt` and `id`.
 *
 * Options (all optional):
 *
 * - connectTimeout (float, 2.0) and timeout (float, 5.0): seconds, for the default transports
 * - transport (Transport): replaces the default; it owns its timeouts
 * - bufferDir (string): defaults to sys_get_temp_dir()/sonar-php/<hash of url and token>
 * - bufferLimit (int, 1000): the most Pulses kept; the oldest are dropped beyond it
 * - flushLimit (int, 50) and flushSeconds (float, 3.0): what one call may spend on resending the buffer
 * - clock (callable returning DateTimeImmutable): for tests
 */
final class SonarClient
{
    private const LOG_BYTES = 65536;
    private const MAX_BODY_BYTES = 75000; // sonar refuses more than 80 KB
    private const MAX_VALUES = 50;
    private const MAX_DURATION = 4294967;
    private const OPTIONS = ['connectTimeout', 'timeout', 'transport', 'bufferDir', 'bufferLimit', 'flushLimit', 'flushSeconds', 'clock'];

    /** @var string */
    private $url;

    /** @var string */
    private $token;

    /** @var Transport */
    private $transport;

    /** @var callable(): \DateTimeImmutable */
    private $clock;

    /** @var Buffer */
    private $buffer;

    /** @var int */
    private $flushLimit;

    /** @var float */
    private $flushSeconds;

    /**
     * @param string               $url     the base URL of sonar, e.g. https://sonar.example.com
     * @param array<string, mixed> $options
     *
     * @throws \InvalidArgumentException for an empty URL or token and for an unknown option
     */
    public function __construct(string $url, string $token, array $options = [])
    {
        if ('' === trim($url) || '' === $token) {
            throw new \InvalidArgumentException('Both the URL and the token of sonar are needed.');
        }
        $unknown = array_diff(array_keys($options), self::OPTIONS);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(sprintf('Unknown option(s): %s.', implode(', ', $unknown)));
        }
        $this->url = rtrim(trim($url), '/');
        $this->token = $token;
        $this->clock = $options['clock'] ?? static function (): \DateTimeImmutable {
            return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        };
        $this->transport = $options['transport'] ?? self::defaultTransport((float) ($options['connectTimeout'] ?? 2.0), (float) ($options['timeout'] ?? 5.0));
        $this->flushLimit = (int) ($options['flushLimit'] ?? 50);
        $this->flushSeconds = (float) ($options['flushSeconds'] ?? 3.0);
        $this->buffer = new Buffer(
            $options['bufferDir'] ?? sys_get_temp_dir().'/sonar-php/'.substr(hash('sha256', $this->url."\n".$this->token), 0, 16),
            (int) ($options['bufferLimit'] ?? 1000),
            $this->clock
        );
    }

    /**
     * Sends a Pulse: "this job ran". The buffer is resent first.
     *
     * @param array<string, mixed> $fields status (ok, warn, fail; default ok), count, duration (seconds), values
     *                                     (name => scalar), log (the tail is kept)
     */
    public function pulse(string $check, array $fields = []): PulseResult
    {
        return $this->pulseAt($check, $fields, $this->now());
    }

    /**
     * Runs the job, measures how long it takes and sends the Pulse: ok when it returns, fail with the exception
     * message as the log when it throws (the exception is rethrown). The job can set count, values, log and status
     * on the Run it gets. The Pulse counts at the time the job started.
     *
     * @param callable(Run): mixed $job
     *
     * @return mixed what the job returned
     */
    public function run(string $check, callable $job)
    {
        $run = new Run();
        $started = $this->now();
        try {
            $return = $job($run);
        } catch (\Throwable $e) {
            $run->setStatus('fail')->setLog($e->getMessage());
            $this->pulseAt($check, $run->toFields() + ['duration' => $this->secondsSince($started)], $started);

            throw $e;
        }
        $this->pulseAt($check, $run->toFields() + ['duration' => $this->secondsSince($started)], $started);

        return $return;
    }

    /**
     * Resends the buffer, all of it unless limited. Never throws.
     */
    public function flush(int $maxPulses = PHP_INT_MAX, ?float $maxSeconds = null): FlushResult
    {
        try {
            return $this->buffer->flush(function (string $check, string $json): PulseResult {
                return $this->deliver($check, $json, null);
            }, $maxPulses, $maxSeconds);
        } catch (\Throwable $e) {
            Log::error('The buffer could not be flushed: '.$e->getMessage());

            return new FlushResult(0, 0, 0, true);
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function pulseAt(string $check, array $fields, \DateTimeImmutable $ranAt): PulseResult
    {
        $id = null;
        try {
            $body = $this->body($fields, $ranAt);
            $id = $body['id'];
            $json = $this->encode($body);
            $flush = $this->flush($this->flushLimit, $this->flushSeconds);
            $result = $flush->hasFailed()
                ? PulseResult::buffered($id, null, 'Sonar could not be reached, so the Pulse was not even tried.')
                : $this->deliver($check, $json, $id);
            if ($result->isBuffered()) {
                $this->store($check, $body, $ranAt);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error(sprintf('Pulse "%s" was dropped: %s', $check, $e->getMessage()));

            return PulseResult::rejected($id, null, 'Dropped, the Pulse could not be sent or buffered: '.$e->getMessage());
        }
    }

    /**
     * The JSON body; a log whose escaped form would push it past what sonar takes is cut further.
     *
     * @param array<string, mixed> $body
     */
    private function encode(array &$body): string
    {
        do {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (false === $json) {
                throw new \RuntimeException('The Pulse cannot be encoded as JSON: '.json_last_error_msg());
            }
            if (strlen($json) <= self::MAX_BODY_BYTES || !isset($body['log']) || '' === $body['log']) {
                return $json;
            }
            $body['log'] = self::tail(substr($body['log'], -(int) (strlen($body['log']) * 0.75)));
        } while (true);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws \RuntimeException
     */
    private function store(string $check, array $body, \DateTimeImmutable $ranAt): void
    {
        $this->buffer->add($check, $body, $ranAt);
    }

    private function deliver(string $check, string $json, ?string $id): PulseResult
    {
        $url = $this->url.'/intake/v1/pulse/'.rawurlencode($check);
        try {
            $response = $this->transport->send($url, [
                'Authorization' => 'Bearer '.$this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], $json);
        } catch (\Throwable $e) {
            return PulseResult::buffered($id, null, 'No answer from sonar: '.$e->getMessage());
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return PulseResult::sent($id, $status);
        }
        if ($status >= 500 || 429 === $status) {
            return PulseResult::buffered($id, $status, 'Sonar answered '.$status.'.');
        }
        // 401, 403, 404, 422, and any other answer a resend cannot change
        $title = $this->problemTitle($response->getBody());
        $message = trim($status.' '.$title);
        Log::error(sprintf('Pulse "%s" was refused and dropped: %s', $check, $message));

        return PulseResult::rejected($id, $status, 'Refused by sonar: '.$message);
    }

    private function problemTitle(string $body): string
    {
        $problem = json_decode($body);
        $title = $problem instanceof \stdClass && is_string($problem->title ?? null) ? $problem->title : '';

        return substr($title, 0, 200);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function body(array $fields, \DateTimeImmutable $ranAt): array
    {
        $status = $fields['status'] ?? 'ok';
        if (!in_array($status, ['ok', 'warn', 'fail'], true)) {
            Log::error(sprintf('Ignored the unknown status "%s", sent ok.', is_scalar($status) ? (string) $status : gettype($status)));
            $status = 'ok';
        }
        $body = [
            'id' => self::uuid(),
            'ranAt' => $ranAt->format('Y-m-d\TH:i:s.uP'),
            'status' => $status,
        ];
        // Optional fields that sonar would refuse (422) are cleaned up instead, so they never cost the whole Pulse.
        if (isset($fields['count']) && (int) $fields['count'] >= 0) {
            $body['count'] = (int) $fields['count'];
        }
        if (isset($fields['duration'])) {
            $body['duration'] = round(max(0.0, min((float) $fields['duration'], self::MAX_DURATION)), 3);
        }
        $values = isset($fields['values']) && is_array($fields['values']) ? self::values($fields['values']) : [];
        if ([] !== $values) {
            $body['values'] = (object) $values;
        }
        if (isset($fields['log'])) {
            $body['log'] = self::tail((string) $fields['log']);
        }

        return $body;
    }

    /**
     * At most 50 scalar values; longer strings are cut to 500 characters.
     *
     * @param array<mixed> $values
     *
     * @return array<string, bool|int|float|string>
     */
    private static function values(array $values): array
    {
        $clean = [];
        foreach ($values as $name => $value) {
            if (count($clean) >= self::MAX_VALUES) {
                break;
            }
            if (is_string($value)) {
                $value = self::cut($value, 500);
            }
            if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
                $clean[(string) $name] = $value;
            }
        }

        return $clean;
    }

    /**
     * A string of at most $characters characters (bytes without ext-mbstring).
     */
    private static function cut(string $value, int $characters): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $characters) : substr($value, 0, $characters);
    }

    /**
     * The last LOG_BYTES bytes of a log, starting at a whole character (sonar keeps the tail as well).
     */
    private static function tail(string $log): string
    {
        if (strlen($log) <= self::LOG_BYTES) {
            return $log;
        }
        $tail = substr($log, -self::LOG_BYTES);
        $skip = 0;
        while ($skip < 3 && 0x80 === (ord($tail[$skip]) & 0xC0)) {
            ++$skip;
        }

        return substr($tail, $skip);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    private function secondsSince(\DateTimeImmutable $start): float
    {
        return (float) $this->now()->format('U.u') - (float) $start->format('U.u');
    }

    private function now(): \DateTimeImmutable
    {
        return call_user_func($this->clock);
    }

    private static function defaultTransport(float $connectTimeout, float $timeout): Transport
    {
        return extension_loaded('curl') ? new CurlTransport($connectTimeout, $timeout) : new StreamTransport($timeout);
    }
}
