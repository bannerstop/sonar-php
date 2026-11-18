<?php

declare(strict_types=1);

// Router of the stub HTTP server the transport tests start with PHP's built-in server. It records every request
// in $STUB_STATE/requests and answers as told by $STUB_STATE/mode: a status code, optionally "sleep:<ms>:<status>".

$state = getenv('STUB_STATE');
$headers = [];
foreach (getallheaders() as $name => $value) {
    $headers[$name] = $value;
}
@mkdir($state.'/requests');
file_put_contents(
    sprintf('%s/requests/%s-%s.json', $state, number_format(microtime(true), 6, '.', ''), uniqid()),
    json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'uri' => $_SERVER['REQUEST_URI'],
        'headers' => $headers,
        'body' => file_get_contents('php://input'),
    ])
);

$mode = trim((string) @file_get_contents($state.'/mode')) ?: '202';
$status = (int) $mode;
if (0 === strpos($mode, 'sleep:')) {
    list(, $milliseconds, $status) = explode(':', $mode) + [null, 0, 202];
    usleep((int) $milliseconds * 1000);
}
if (0 === strpos($mode, 'retry:')) {
    list(, $status, $seconds) = explode(':', $mode);
    header('Retry-After: '.$seconds);
}
http_response_code((int) $status);
header('Content-Type: application/json');
echo 202 === (int) $status ? '{"status":"accepted"}' : '{"title":"Stub answered '.(int) $status.'"}';
