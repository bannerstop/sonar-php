<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests\Support;

final class FakeClock
{
    /** @var int microseconds since the epoch */
    private $micro;

    public function __construct(string $now = '2026-11-18T10:00:00+00:00')
    {
        $start = new \DateTimeImmutable($now);
        $this->micro = $start->getTimestamp() * 1000000 + (int) $start->format('u');
    }

    public function __invoke(): \DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($this->micro, 1000000), $this->micro % 1000000));
        if (false === $time) {
            throw new \LogicException('The fake time is not valid.');
        }

        return $time;
    }

    public function advance(float $seconds): void
    {
        $this->micro += (int) round($seconds * 1000000);
    }
}
