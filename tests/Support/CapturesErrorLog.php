<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests\Support;

/**
 * Sends error_log() to a file for the rest of the test. Call it from the test, not from setUp(): PHPUnit puts its
 * own error_log back between setUp() and the test method.
 */
trait CapturesErrorLog
{
    /** @var string|null */
    private $errorLogFile;

    /** @var string|false */
    private $previousErrorLog = false;

    protected function captureErrorLog(string $file): void
    {
        if (null !== $this->errorLogFile) {
            return;
        }
        $this->errorLogFile = $file;
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $file);
    }

    protected function capturedErrorLog(): string
    {
        return null !== $this->errorLogFile && is_file($this->errorLogFile) ? (string) file_get_contents($this->errorLogFile) : '';
    }

    protected function releaseErrorLog(): void
    {
        if (null !== $this->errorLogFile) {
            ini_set('error_log', (string) $this->previousErrorLog);
            $this->errorLogFile = null;
        }
    }
}
