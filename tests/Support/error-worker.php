<?php

declare(strict_types=1);

// A process with the error handlers registered: php error-worker.php <url> <buffer dir> <scenario>

require dirname(__DIR__, 2).'/vendor/autoload.php';

putenv('SONAR_URL='.$argv[1]);
putenv('SONAR_TOKEN=invented-token');
putenv('SONAR_ENVIRONMENT=test');

switch ($argv[3]) {
    case 'previous-handler':
        set_exception_handler(static function (\Throwable $e): void {
            echo 'previous handler saw: '.$e->getMessage();
        });
        \Bannerstop\Sonar\Sonar::registerErrorHandler(['bufferDir' => $argv[2]]);
        throw new \RuntimeException('chained');
    case 'uncaught':
        \Bannerstop\Sonar\Sonar::registerErrorHandler(['bufferDir' => $argv[2]]);
        throw new \RuntimeException('uncaught in the worker');
    case 'fatal':
        \Bannerstop\Sonar\Sonar::registerErrorHandler(['bufferDir' => $argv[2]]);
        ini_set('memory_limit', '16M');
        $big = str_repeat('x', 64 * 1024 * 1024);
        break;
    case 'caught-and-reported':
        \Bannerstop\Sonar\Sonar::registerErrorHandler(['bufferDir' => $argv[2]]);
        \Bannerstop\Sonar\Sonar::reportException(new \RuntimeException('handled'));
        echo 'still running';
}
