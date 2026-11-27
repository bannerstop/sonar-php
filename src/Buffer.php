<?php

declare(strict_types=1);

namespace Bannerstop\Sonar;

/**
 * The on-disk buffer: one JSON file per Pulse or error item (`{"check": ..., "body": {...}}`), named by its time (`ranAt` of a Pulse, `occurredAt` of an error item) so a directory
 * listing is the send order, written to a temporary file and renamed so a crash never leaves half a file.
 *
 * @internal
 */
final class Buffer
{
    public const MAX_AGE = 604800; // 7 days, the oldest time sonar takes
    private const LOCK = '.lock';
    private const RETRY_AFTER = '.retry-after';
    private const STALE_TEMP = 3600;

    /** @var string */
    private $dir;

    /** @var int */
    private $limit;

    /** @var callable(): \DateTimeImmutable */
    private $clock;

    /** @var string */
    private $timeField;

    /**
     * @param callable(): \DateTimeImmutable $clock
     * @param string                         $timeField the body field that holds the time an entry counts at
     */
    public function __construct(string $dir, int $limit, callable $clock, string $timeField = 'ranAt')
    {
        $this->dir = $dir;
        $this->limit = $limit;
        $this->clock = $clock;
        $this->timeField = $timeField;
    }

    /**
     * @param array<string, mixed> $body the body, with the time field (`ranAt` for Pulses); an `id` names the file
     *
     * @throws \RuntimeException when the file cannot be written
     */
    public function add(string $check, array $body, \DateTimeImmutable $ranAt): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException(sprintf('The buffer directory %s cannot be created.', $this->dir));
        }
        $json = Json::encode(['check' => $check, 'body' => $body]);
        $name = $ranAt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THisu').'-'.($body['id'] ?? bin2hex(random_bytes(8))).'.json';
        $temp = $this->dir.'/.'.bin2hex(random_bytes(6)).'.tmp';
        if (false === @file_put_contents($temp, $json) || !@rename($temp, $this->dir.'/'.$name)) {
            @unlink($temp);

            throw new \RuntimeException(sprintf('The buffer directory %s is not writable.', $this->dir));
        }
        $this->enforceLimit();
    }

    /**
     * Sonar asked to wait (Retry-After): nothing is sent from this buffer before `$epoch` (seconds since the epoch).
     *
     * @throws \RuntimeException
     */
    public function backOffUntil(float $epoch): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException(sprintf('The buffer directory %s cannot be created.', $this->dir));
        }
        if (false === @file_put_contents($this->dir.'/'.self::RETRY_AFTER, sprintf('%.3F', $epoch))) {
            throw new \RuntimeException(sprintf('The buffer directory %s is not writable.', $this->dir));
        }
    }

    /**
     * Seconds since the epoch before which nothing is sent, 0 when there is no wait.
     */
    public function backedOffUntil(): float
    {
        $content = @file_get_contents($this->dir.'/'.self::RETRY_AFTER);

        return false === $content ? 0.0 : (float) $content;
    }

    /**
     * The current time in seconds since the epoch, by the buffer's clock.
     */
    public function now(): float
    {
        return $this->epoch();
    }

    /**
     * Sends the buffered Pulses oldest first, until the budget is used up or one cannot be delivered.
     *
     * @param callable(string, string): PulseResult $deliver check name and JSON body; sent and rejected Pulses are
     *                                                       removed from the buffer, a buffered result stops the flush
     */
    public function flush(callable $deliver, int $maxPulses, ?float $maxSeconds): FlushResult
    {
        if (!is_dir($this->dir)) {
            return new FlushResult(0, 0, 0, false);
        }
        $lock = @fopen($this->dir.'/'.self::LOCK, 'c');
        if (false === $lock) {
            throw new \RuntimeException(sprintf('The buffer directory %s is not writable.', $this->dir));
        }
        try {
            if ($this->backedOffUntil() > $this->epoch() || !flock($lock, LOCK_EX | LOCK_NB)) {
                return new FlushResult(0, 0, count($this->files()), false, true);
            }

            return $this->flushLocked($deliver, $maxPulses, $maxSeconds);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param callable(string, string): PulseResult $deliver
     */
    private function flushLocked(callable $deliver, int $maxPulses, ?float $maxSeconds): FlushResult
    {
        $started = $this->epoch();
        $sent = $dropped = $attempts = 0;
        $failed = false;
        $this->removeStaleTempFiles();
        $oldest = $this->epoch() - self::MAX_AGE;

        foreach ($this->files() as $file) {
            $entry = $this->read($file);
            if (null === $entry) {
                Log::error(sprintf('Dropped the unreadable buffer file %s.', basename($file)));
                @unlink($file);
                ++$dropped;
                continue;
            }
            if ($entry['ranAt'] < $oldest) {
                @unlink($file);
                ++$dropped;
                continue;
            }
            if ($attempts >= $maxPulses || (null !== $maxSeconds && $this->epoch() - $started >= $maxSeconds)) {
                break;
            }
            ++$attempts;
            $result = $deliver($entry['check'], $entry['json']);
            if ($result->isBuffered()) {
                $failed = true;
                break;
            }
            @unlink($file);
            $result->isSent() ? ++$sent : ++$dropped;
        }

        return new FlushResult($sent, $dropped, count($this->files()), $failed);
    }

    /**
     * @return array{check: string, json: string, ranAt: float}|null
     */
    private function read(string $file): ?array
    {
        $content = @file_get_contents($file);
        $entry = false === $content ? null : json_decode($content);
        if (!$entry instanceof \stdClass || !is_string($entry->check ?? null) || !($entry->body ?? null) instanceof \stdClass) {
            return null;
        }
        $time = $entry->body->{$this->timeField} ?? null;
        $ranAt = is_string($time) ? date_create_immutable($time) : false;
        if (false === $ranAt) {
            return null;
        }
        $json = Json::encode($entry->body);

        return ['check' => $entry->check, 'json' => $json, 'ranAt' => (float) $ranAt->format('U.u')];
    }

    /**
     * @return list<string> full paths, oldest first
     */
    private function files(): array
    {
        $files = glob($this->dir.'/*.json');

        return false === $files ? [] : $files;
    }

    private function enforceLimit(): void
    {
        $files = $this->files();
        foreach (array_slice($files, 0, max(0, count($files) - $this->limit)) as $file) {
            Log::error(sprintf('The buffer is full (%d Pulses); dropped the oldest, %s.', $this->limit, basename($file)));
            @unlink($file);
        }
    }

    private function removeStaleTempFiles(): void
    {
        foreach (glob($this->dir.'/.*.tmp') ?: [] as $file) {
            if (time() - (int) @filemtime($file) > self::STALE_TEMP) {
                @unlink($file);
            }
        }
    }

    private function epoch(): float
    {
        return (float) call_user_func($this->clock)->format('U.u');
    }
}
