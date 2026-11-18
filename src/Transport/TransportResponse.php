<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Transport;

final class TransportResponse
{
    /** @var int */
    private $status;

    /** @var string */
    private $body;

    /** @var array<string, string> */
    private $headers = [];

    /**
     * @param array<string, string> $headers header name => value, any case
     */
    public function __construct(int $status, string $body = '', array $headers = [])
    {
        $this->status = $status;
        $this->body = $body;
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = $value;
        }
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * The value of a header, whatever the case of its name, or null.
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
