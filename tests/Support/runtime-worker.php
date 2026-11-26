<?php

declare(strict_types=1);

// A process that reports its runtime with the default scheduling: php runtime-worker.php <url> <marker dir>
// It prints "request done" first; the report must go out at shutdown, after that.

require dirname(__DIR__, 2).'/vendor/autoload.php';

putenv('SONAR_URL='.$argv[1]);
putenv('SONAR_TOKEN=invented-token');
putenv('SONAR_SERVICE=checkout');
\Bannerstop\Sonar\Sonar::reportRuntime(null, ['markerDir' => $argv[2]]);
echo 'request done';
