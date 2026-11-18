<?php

declare(strict_types=1);

namespace Bannerstop\Sonar;

use Bannerstop\Sonar\Transport\CurlTransport;
use Bannerstop\Sonar\Transport\StreamTransport;
use Bannerstop\Sonar\Transport\Transport;
use Bannerstop\Sonar\Transport\TransportResponse;

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
 * - clock (callable returning DateTimeImmutable): for tests only
 */
final class SonarClient
{
    private const LOG_BYTES = 65536;
    private const MAX_BODY_BYTES = 75000; // sonar refuses more than 80 KB
    private const MAX_VALUES = 50;
    private const MAX_STRING = 500;
    private const MAX_DURATION = 4294967;
    private const MAX_RETRY_AFTER = 3600;
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
     * @param array<string, mixed> $fields status (ok, warn, fail; default ok), count (int >= 0), duration (seconds),
     *                                     values (name => scalar, at most 50), log (the tail is kept). An invalid
     *                                     optional field is left out and logged; an unknown status rejects the Pulse.
     */
    public function pulse(string $check, array $fields = []): PulseResult
    {
        return $this->send($check, $fields, $this->now(), false);
    }

    /**
     * Resends the buffer, runs the job, measures how long it takes and sends the Pulse: ok when it returns, fail
     * with the exception message as the log when it throws (the exception is rethrown). The job can set count,
     * values, log and status on the Run it gets. The Pulse counts at the time the job started.
     *
     * @param callable(Run): mixed $job
     *
     * @return mixed what the job returned
     */
    public function run(string $check, callable $job)
    {
        $blocked = $this->resend();
        $run = new Run();
        $started = $this->now();
        try {
            $return = $job($run);
        } catch (\Throwable $e) {
            $run->setStatus(Status::FAIL)->setLog($e->getMessage());
            $this->send($check, $run->toFields() + ['duration' => $this->secondsSince($started)], $started, $blocked);

            throw $e;
        }
        $this->send($check, $run->toFields() + ['duration' => $this->secondsSince($started)], $started, $blocked);

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
     * Resends the buffer within the budget of one call.
     *
     * @return string|null why a new Pulse should not be sent now (it is buffered instead), null when it can be
     */
    private function resend(): ?string
    {
        if ($this->buffer->backedOffUntil() > $this->buffer->now()) {
            return 'Sonar asked to wait (Retry-After), so the Pulse was not sent.';
        }

        return $this->flush($this->flushLimit, $this->flushSeconds)->hasFailed()
            ? 'Sonar could not be reached, so the Pulse was not even tried.'
            : null;
    }

    /**
     * @param array<string, mixed> $fields
     * @param string|null|false    $blocked the outcome of resend() when the caller already did it, false when not
     */
    private function send(string $check, array $fields, \DateTimeImmutable $ranAt, $blocked): PulseResult
    {
        $id = null;
        try {
            if ('' === $check || false !== strpos($check, '/')) {
                return $this->refuse(null, sprintf('The check name "%s" is empty or contains a slash; sonar names are single path segments.', $check));
            }
            if (isset($fields['status']) && !Status::isValid($fields['status'])) {
                return $this->refuse(null, 'The status has to be one of ok, warn, fail.');
            }
            $body = $this->fit($this->body($check, $fields, $ranAt));
            $id = $body['id'];
            $blocked = false === $blocked ? $this->resend() : $blocked;
            $result = null === $blocked ? $this->deliver($check, Json::encode($body), $id) : PulseResult::buffered($id, null, $blocked);
            if ($result->isBuffered()) {
                $this->buffer->add($check, $body, $ranAt);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error(sprintf('Pulse "%s" was dropped: %s', $check, $e->getMessage()));

            return PulseResult::rejected($id, null, 'Dropped, the Pulse could not be sent or buffered: '.$e->getMessage());
        }
    }

    private function refuse(?string $id, string $message): PulseResult
    {
        Log::error('Pulse dropped: '.$message);

        return PulseResult::rejected($id, null, 'Dropped: '.$message);
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
        // Temporary: sonar busy or down, or a redirect, which says the URL is wrong, not that the Pulse is.
        if ($status >= 500 || $status < 400 || in_array($status, [408, 425, 429], true)) {
            if (429 === $status || 503 === $status) {
                $this->backOff($response);
            }

            return PulseResult::buffered($id, $status, 'Sonar answered '.$status.'.');
        }
        // 401, 403, 404, 422, and any other 4xx: a resend cannot change them
        $message = trim($status.' '.$this->problemTitle($response->getBody()));
        Log::error(sprintf('Pulse "%s" was refused and dropped: %s', $check, $message));

        return PulseResult::rejected($id, $status, 'Refused by sonar: '.$message);
    }

    /**
     * Honours Retry-After (seconds or an HTTP date, at most an hour): nothing is sent until then.
     */
    private function backOff(TransportResponse $response): void
    {
        $header = $response->getHeader('Retry-After');
        if (null === $header || '' === trim($header)) {
            return;
        }
        $header = trim($header);
        $now = $this->buffer->now();
        if (ctype_digit($header)) {
            $wait = (float) $header;
        } else {
            $date = strtotime($header);
            $wait = false === $date ? 0.0 : $date - $now;
        }
        if ($wait <= 0) {
            return;
        }
        try {
            $this->buffer->backOffUntil($now + min($wait, self::MAX_RETRY_AFTER));
        } catch (\Throwable $e) {
            Log::error('Retry-After could not be stored: '.$e->getMessage());
        }
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
    private function body(string $check, array $fields, \DateTimeImmutable $ranAt): array
    {
        $body = [
            'id' => self::uuid(),
            'ranAt' => $ranAt->format('Y-m-d\TH:i:s.uP'),
            'status' => $fields['status'] ?? Status::OK,
        ];
        $count = $fields['count'] ?? null;
        if (null !== $count) {
            is_int($count) && $count >= 0 ? $body['count'] = $count : $this->leftOut($check, 'count', 'it has to be an integer of 0 or more');
        }
        $duration = $fields['duration'] ?? null;
        if (null !== $duration) {
            (is_int($duration) || is_float($duration)) && is_finite((float) $duration) && $duration >= 0 && $duration <= self::MAX_DURATION
                ? $body['duration'] = round((float) $duration, 3)
                : $this->leftOut($check, 'duration', sprintf('it has to be a number of seconds from 0 to %d', self::MAX_DURATION));
        }
        $values = $fields['values'] ?? null;
        if (null !== $values) {
            if (self::validValues($values)) {
                [] !== $values && $body['values'] = (object) $values;
            } else {
                $this->leftOut($check, 'values', sprintf('it has to hold at most %d numbers, flags or strings of at most %d characters', self::MAX_VALUES, self::MAX_STRING));
            }
        }
        $log = $fields['log'] ?? null;
        if (null !== $log) {
            is_scalar($log) ? $body['log'] = self::tail((string) $log) : $this->leftOut($check, 'log', 'it has to be a string');
        }

        return $body;
    }

    private function leftOut(string $check, string $field, string $why): void
    {
        Log::error(sprintf('Pulse "%s": left out the invalid "%s", %s.', $check, $field, $why));
    }

    /**
     * @param mixed $values
     */
    private static function validValues($values): bool
    {
        if (!is_array($values) || count($values) > self::MAX_VALUES) {
            return false;
        }
        foreach ($values as $value) {
            if (is_string($value) ? self::cut($value, self::MAX_STRING) !== $value : !(is_bool($value) || is_int($value) || is_float($value))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The body, with a log cut further when its escaped form would push the body past what sonar takes.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function fit(array $body): array
    {
        while (strlen(Json::encode($body)) > self::MAX_BODY_BYTES && isset($body['log']) && '' !== $body['log']) {
            $body['log'] = self::tail(substr($body['log'], -(int) (strlen($body['log']) * 0.75)));
        }

        return $body;
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
