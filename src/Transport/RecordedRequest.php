<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Transport;

/**
 * A request an InMemoryTransport received.
 */
final class RecordedRequest
{
    /** @var string */
    private $url;

    /** @var array<string, string> */
    private $headers;

    /** @var string */
    private $body;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(string $url, array $headers, string $body)
    {
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * The value of a header, whatever the case of its name, or null.
     */
    public function getHeader(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (0 === strcasecmp($key, $name)) {
                return $value;
            }
        }

        return null;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * The JSON body as an array.
     *
     * @return array<string, mixed>
     */
    public function getJson(): array
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : [];
    }
}
