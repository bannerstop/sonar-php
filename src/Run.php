<?php

declare(strict_types=1);

namespace Bannerstop\Sonar;

/**
 * What a job reports from inside `run()`. The setters can be chained.
 */
final class Run
{
    /** @var string */
    private $status = Status::OK;

    /** @var int|null */
    private $count;

    /** @var array<string, bool|int|float|string> */
    private $values = [];

    /** @var string|null */
    private $log;

    /**
     * @param string $status ok, warn or fail
     *
     * @return $this
     */
    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return $this
     */
    public function setCount(int $count): self
    {
        $this->count = $count;

        return $this;
    }

    /**
     * @param array<string, bool|int|float|string> $values at most 50 entries; strings of at most 500 characters
     *
     * @return $this
     */
    public function setValues(array $values): self
    {
        $this->values = $values;

        return $this;
    }

    /**
     * @return $this
     */
    public function setLog(string $log): self
    {
        $this->log = $log;

        return $this;
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toFields(): array
    {
        $fields = ['status' => $this->status];
        if (null !== $this->count) {
            $fields['count'] = $this->count;
        }
        if ([] !== $this->values) {
            $fields['values'] = $this->values;
        }
        if (null !== $this->log) {
            $fields['log'] = $this->log;
        }

        return $fields;
    }
}
