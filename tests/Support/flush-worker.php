<?php

declare(strict_types=1);

// A second process that flushes a buffer: php flush-worker.php <autoload> <url> <buffer dir>
// Prints the report as JSON.

require $argv[1];

$client = new \Bannerstop\Sonar\SonarClient($argv[2], 'invented-token', ['bufferDir' => $argv[3]]);
$report = $client->flush();
echo json_encode(['sent' => $report->getSent(), 'skipped' => $report->wasSkipped(), 'failed' => $report->hasFailed()]);
