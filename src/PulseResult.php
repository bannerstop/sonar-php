<?php

declare(strict_types=1);

namespace Bannerstop\Sonar;

/**
 * What became of one Pulse. `pulse()` and `run()` return it and never throw.
 *
 * - sent: sonar accepted the Pulse (any 2xx, including 202 "pending" for a check name sonar does not know yet).
 * - buffered: sonar could not be reached or was busy (network error, timeout, 5xx, 429); the Pulse waits in the
 *   buffer and is resent, with the same `id` and `ranAt`, by a later call or by `sonar-flush`.
 * - rejected: the Pulse will never arrive and was dropped, either because sonar refused it for good (401, 403, 404,
 *   422 and other 4xx) or because the client could not even buffer it (unwritable buffer directory, no configuration).
 *   `getMessage()` says which.
 */
final class PulseResult
{
    public const SENT = 'sent';
    public const BUFFERED = 'buffered';
    public const REJECTED = 'rejected';

    /** @var string */
    private $outcome;

    /** @var string|null */
    private $id;

    /** @var int|null */
    private $statusCode;

    /** @var string|null */
    private $message;

    private function __construct(string $outcome, ?string $id, ?int $statusCode, ?string $message)
    {
        $this->outcome = $outcome;
        $this->id = $id;
        $this->statusCode = $statusCode;
        $this->message = $message;
    }

    /**
     * @internal
     */
    public static function sent(?string $id, ?int $statusCode = null): self
    {
        return new self(self::SENT, $id, $statusCode, null);
    }

    /**
     * @internal
     */
    public static function buffered(?string $id, ?int $statusCode, string $message): self
    {
        return new self(self::BUFFERED, $id, $statusCode, $message);
    }

    /**
     * @internal
     */
    public static function rejected(?string $id, ?int $statusCode, string $message): self
    {
        return new self(self::REJECTED, $id, $statusCode, $message);
    }

    /**
     * @return string one of the constants SENT, BUFFERED, REJECTED
     */
    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function isSent(): bool
    {
        return self::SENT === $this->outcome;
    }

    public function isBuffered(): bool
    {
        return self::BUFFERED === $this->outcome;
    }

    public function isRejected(): bool
    {
        return self::REJECTED === $this->outcome;
    }

    /**
     * The client-generated UUID of the Pulse, null when it could not be built.
     */
    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * The HTTP status sonar answered with, null when there was no answer.
     */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * Why the Pulse was buffered or rejected.
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }
}
