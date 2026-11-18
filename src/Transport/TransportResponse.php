<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Transport;

final class TransportResponse
{
    /** @var int */
    private $status;

    /** @var string */
    private $body;

    public function __construct(int $status, string $body = '')
    {
        $this->status = $status;
        $this->body = $body;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
