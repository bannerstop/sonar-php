<?php

declare(strict_types=1);

// A second process that flushes a buffer: php flush-worker.php <url> <buffer dir>
// Prints the report as JSON.

require dirname(__DIR__, 2).'/vendor/autoload.php';

$client = new \Bannerstop\Sonar\SonarClient($argv[1], 'invented-token', ['bufferDir' => $argv[2]]);
$report = $client->flush();
echo json_encode(['sent' => $report->getSent(), 'skipped' => $report->wasSkipped(), 'failed' => $report->hasFailed()]);
