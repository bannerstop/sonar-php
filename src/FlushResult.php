<?php

declare(strict_types=1);

namespace Bannerstop\Sonar;

/**
 * What a flush of the buffer did.
 */
final class FlushResult
{
    /** @var int */
    private $sent;

    /** @var int */
    private $dropped;

    /** @var int */
    private $remaining;

    /** @var bool */
    private $failed;

    /** @var bool */
    private $skipped;

    /**
     * @internal
     */
    public function __construct(int $sent, int $dropped, int $remaining, bool $failed, bool $skipped = false)
    {
        $this->sent = $sent;
        $this->dropped = $dropped;
        $this->remaining = $remaining;
        $this->failed = $failed;
        $this->skipped = $skipped;
    }

    /**
     * Pulses sonar accepted.
     */
    public function getSent(): int
    {
        return $this->sent;
    }

    /**
     * Pulses deleted unsent (older than 7 days, unreadable) or refused for good.
     */
    public function getDropped(): int
    {
        return $this->dropped;
    }

    /**
     * Pulses still in the buffer.
     */
    public function getRemaining(): int
    {
        return $this->remaining;
    }

    /**
     * True when sonar could not be reached or was busy, so the flush stopped.
     */
    public function hasFailed(): bool
    {
        return $this->failed;
    }

    /**
     * True when nothing was done: another process was flushing the same buffer, or sonar asked to wait (Retry-After).
     */
    public function wasSkipped(): bool
    {
        return $this->skipped;
    }
}
