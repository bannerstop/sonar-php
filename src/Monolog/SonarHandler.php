<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Monolog;

use Bannerstop\Sonar\ErrorReporter;
use Monolog\Handler\HandlerInterface;

/**
 * A Monolog handler that reports records to sonar through an ErrorReporter. It works with Monolog 2 and 3: the
 * methods take the record untyped (an array in 2, a LogRecord in 3, which reads like an array) and use only what
 * both have, so no Monolog class is needed to load it. It never throws into the logger.
 *
 * A record becomes a `log` item. A record at `error` or above whose context holds an `exception` becomes an
 * `error` item with that exception and the record's message as `context.logMessage`. A `fingerprint` in the
 * context is the application's own grouping key. The channel is added to the context; Monolog's `extra` is not
 * sent (processors put IP addresses and user data there).
 */
final class SonarHandler implements HandlerInterface
{
    private const LEVELS = ['debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300, 'error' => 400, 'critical' => 500, 'alert' => 550, 'emergency' => 600];

    /** @var ErrorReporter */
    private $reporter;

    /** @var int */
    private $threshold;

    /** @var bool */
    private $bubble;

    /**
     * @param int|string|object $level  the lowest record level sent: a Monolog level number, a level name or a Monolog 3 `Level`; warning by default
     * @param bool              $bubble whether records go on to the handlers below this one
     *
     * @throws \InvalidArgumentException for a level that does not exist
     */
    public function __construct(ErrorReporter $reporter, $level = 300, bool $bubble = true)
    {
        $this->reporter = $reporter;
        $this->threshold = self::number($level);
        $this->bubble = $bubble;
    }

    /**
     * @param array<string, mixed>|\ArrayAccess<string, mixed> $record
     */
    public function isHandling($record): bool
    {
        return (int) $record['level'] >= $this->threshold;
    }

    /**
     * @param array<string, mixed>|\ArrayAccess<string, mixed> $record
     */
    public function handle($record): bool
    {
        try {
            if (!$this->isHandling($record)) {
                return false;
            }
            $this->report($record);
        } catch (\Throwable $e) {
            // logging to sonar never breaks the application's logging
        }

        return !$this->bubble;
    }

    /**
     * @param array<int, mixed> $records
     */
    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            $this->handle($record);
        }
    }

    public function close(): void
    {
    }

    /**
     * @param array<string, mixed>|\ArrayAccess<string, mixed> $record
     */
    private function report($record): void
    {
        $level = (int) $record['level'];
        $message = (string) $record['message'];
        $context = is_array($record['context']) ? $record['context'] : [];
        $context['channel'] = (string) $record['channel'];
        $exception = $context['exception'] ?? null;
        unset($context['exception']);
        $sonarLevel = $level >= 500 ? 'critical' : self::name($level);

        if ($exception instanceof \Throwable) {
            if ($level >= 400) {
                $this->reporter->reportThrowable($exception, $context + ['logMessage' => $message], $sonarLevel);

                return;
            }
            $context['exception'] = ['class' => get_class($exception), 'message' => $exception->getMessage()];
        }
        $this->reporter->reportMessage($sonarLevel, $message, $context);
    }

    private static function name(int $level): string
    {
        $name = 'debug';
        foreach (['debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300, 'error' => 400] as $candidate => $number) {
            if ($level >= $number) {
                $name = $candidate;
            }
        }

        return $name;
    }

    /**
     * @param int|string|object $level
     */
    private static function number($level): int
    {
        if (is_object($level) && isset($level->value)) {
            $level = $level->value;
        }
        if (is_string($level) && isset(self::LEVELS[strtolower($level)])) {
            return self::LEVELS[strtolower($level)];
        }
        if (is_int($level) && $level > 0) {
            return $level;
        }

        throw new \InvalidArgumentException('The level has to be a Monolog level number or one of: '.implode(', ', array_keys(self::LEVELS)).'.');
    }
}
