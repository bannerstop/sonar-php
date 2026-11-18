# bannerstop/sonar-php

The PHP client for the Pulses of sonar monitoring: one line tells sonar that a job
ran. It is made for crons and other jobs that must never be slowed down or broken by their own monitoring.

- **Never throws** into your job and waits seconds at most (2 s to connect, 5 s in total by default).
- **Survives outages**: if sonar does not answer, the Pulse is kept in a small on-disk buffer and resent later with
  its original time and id, so sonar judges the run when it happened and counts it once.
- **No dependencies**: PHP 7.2 or newer and `ext-json`. No Guzzle, no PSR packages, no Symfony. Use ext-curl, a
  stream fallback, or your framework's PSR-18 client.

## Installation

```bash
composer require bannerstop/sonar-php
```

PHP `^7.2 || ^8.0`. One release line; projects on PHP below 7.2 cannot use this package.

## Quick start

Set the URL of your sonar and a token of your project (or of the check) in the environment of the job:

```bash
SONAR_URL=https://sonar.example.com
SONAR_TOKEN=...
```

Then report a run:

```php
use Bannerstop\Sonar\Sonar;

Sonar::pulse('nightly-import');
```

With a few facts about the run (all optional):

```php
Sonar::pulse('nightly-import', [
    'status' => 'ok',          // ok (default), warn or fail
    'count' => 1280,           // how many things the job did
    'duration' => 41.7,        // seconds
    'values' => ['imported' => 1280, 'skipped' => 3],
    'log' => "imported 1280 rows, skipped 3\ndone",   // the last 64 KB are kept
]);
```

`pulse()` returns a `PulseResult`. You can ignore it, log it or assert on it:

| Outcome    | Meaning                                                                                       |
|------------|-----------------------------------------------------------------------------------------------|
| `sent`     | sonar accepted it (any 2xx, including `202` for a check name sonar has not seen yet).         |
| `buffered` | sonar did not answer in time or was busy (network error, timeout, 5xx, 429). It will be resent. |
| `rejected` | It will never arrive and was dropped (see below). `getMessage()` says why.                    |

```php
$result = Sonar::pulse('nightly-import');
$result->isSent();       // also isBuffered(), isRejected()
$result->getOutcome();   // 'sent', 'buffered' or 'rejected'
$result->getStatusCode();// the HTTP status, null without an answer
$result->getId();        // the UUID of this Pulse
```

Optional fields sonar would refuse are cleaned up first (unknown `status` becomes `ok`, negative `count` is left out,
`duration` is clamped, `values` is cut to 50 scalars of 500 characters, an oversized `log` is shortened), so they
never cost the Pulse.

A Pulse is `rejected` when sonar refuses it for good (401 wrong token, 403 not allowed, 404 unknown check, 422 invalid
body, and any other 4xx such as 413: a resend cannot change them) or when the client cannot work (not configured, buffer directory not writable). The client
writes one line to `error_log()` with the status and the problem title, never the token.

## Measuring a job: `run()`

```php
use Bannerstop\Sonar\Run;
use Bannerstop\Sonar\Sonar;

$imported = Sonar::run('nightly-import', function (Run $run) {
    $rows = importRows();
    $run->setCount(count($rows))->setValues(['skipped' => 3]);

    return $rows;
});
```

`run()` measures the duration and sends `ok` when the callback returns; it returns whatever the callback returned. If
the callback throws, it sends `fail` with the exception message as the log and rethrows the exception, so the job
fails as before. Inside the callback you can set `setCount()`, `setValues()`, `setLog()` and `setStatus()`
(for example `warn`). The Pulse counts at the time the job started. The buffer is resent when the Pulse is sent,
after the job, so flushing never counts towards the measured duration.

## Configuration

`Sonar` builds one client on first use from `SONAR_URL` and `SONAR_TOKEN`. To configure it explicitly (or to wire a
client through your DI container), use `Sonar::configure()` or build a `SonarClient` yourself:

```php
Sonar::configure('https://sonar.example.com', $token, ['timeout' => 3.0]);

$client = new \Bannerstop\Sonar\SonarClient('https://sonar.example.com', $token, ['bufferDir' => '/var/lib/myapp/sonar']);
$client->pulse('nightly-import');
$client->run('nightly-import', function ($run) { /* ... */ });
```

`Sonar::configure()` and the `SonarClient` constructor throw `InvalidArgumentException` for an empty URL or token
and for unknown options: that is a programming error, found at bootstrap. Without any configuration `Sonar::pulse()` is rejected (and logged) and `Sonar::run()` just runs the job.

| Option           | Default                                  | Meaning                                                              |
|------------------|------------------------------------------|----------------------------------------------------------------------|
| `connectTimeout` | `2.0`                                    | Seconds to connect (default transports).                             |
| `timeout`        | `5.0`                                    | Seconds for the whole request (default transports).                  |
| `transport`      | cURL, or streams without ext-curl        | A `Transport` object, see below. It owns its timeouts.               |
| `bufferDir`      | `sys_get_temp_dir()/sonar-php/<hash>`    | Where buffered Pulses are kept.                                      |
| `bufferLimit`    | `1000`                                   | Most Pulses kept; the oldest are dropped (with an `error_log()` line). |
| `flushLimit`     | `50`                                     | Most buffered Pulses one call resends.                               |
| `flushSeconds`   | `3.0`                                    | Most seconds one call spends resending.                              |

## The buffer

Every `pulse()` and `run()` first resends the buffer, oldest first, within its budget (50 Pulses or 3 s) and stopping
at the first failure. If that flush found sonar down, the new Pulse goes straight to the buffer instead of waiting on
another timeout. Then the new Pulse is sent. There is no daemon.

- **Location:** `sys_get_temp_dir()/sonar-php/<hash of URL and token>/`, so two tokens on one host never mix. Set
  `bufferDir` to change it. The directory is created with mode `0700`; run all jobs that share a token as the same
  user, or give them a `bufferDir` they can all write.
- **Format:** one JSON file per Pulse (check name and body), written to a temporary file and renamed, so a crash in
  the middle of a write never corrupts the buffer.
- **Resending** keeps the original `ranAt` and `id`. sonar counts a Pulse with a known `id` once, so a resend after a
  lost response is harmless.
- **Dropped without sending:** Pulses older than 7 days (sonar refuses a `ranAt` that old), and the oldest ones when
  more than 1000 are buffered. Pulses that sonar refuses for good during a flush are deleted too.
- **Parallel jobs:** a flush takes an exclusive `flock` on a lock file in the directory. If another process holds it,
  the flush is skipped, so the same Pulse is never sent twice at the same time.
- **Containers:** in a container without a volume the buffer lives in the container's tmp directory and is lost when
  the container is replaced. Mount a volume and set `bufferDir` (or mount one at the temp directory) if Pulses
  buffered during an outage must survive a restart.

### Resending from a cron

A job that rarely runs would only resend its buffer at its next run. To resend sooner, run the bundled command from
a cron, with the same `SONAR_URL` and `SONAR_TOKEN` (and the same user) as the job:

```
*/5 * * * * cd /path/to/project && vendor/bin/sonar-flush
```

It prints a summary (`sent 2, dropped 0, 0 left in the buffer`) and always exits 0, even when sonar is down.

## Transports

The HTTP layer is the `Transport` interface: `send(string $url, array $headers, string $body): TransportResponse`,
throwing `TransportException` only when no answer came back. Every HTTP status is an answer, not an exception.

- **`CurlTransport`**: the default when ext-curl is loaded.
- **`StreamTransport`**: the fallback for installs without ext-curl (needs `allow_url_fopen`). Its timeout bounds the
  connect and each read, not their sum.
- **`Psr18Transport`**: use your framework's HTTP client, proxy settings and profiler. Install a PSR-18 client and
  PSR-17 factories yourself; this package only suggests them:

  ```php
  use Bannerstop\Sonar\SonarClient;
  use Bannerstop\Sonar\Transport\Psr18Transport;
  use Symfony\Component\HttpClient\Psr18Client;

  $psr18 = new Psr18Client(); // implements the client and both factories
  $client = new SonarClient($url, $token, ['transport' => new Psr18Transport($psr18, $psr18, $psr18)]);
  ```

  A custom transport owns its timeouts: configure 2 s to connect and 5 s in total on your client.
- **`InMemoryTransport`**: for your own tests. It records requests and answers as programmed.

  ```php
  $transport = new InMemoryTransport();
  $transport->respondWith(503)->failWith('connection refused');
  $client = new SonarClient('https://sonar.example.com', 'test-token', ['transport' => $transport, 'bufferDir' => $tmp]);

  $client->pulse('nightly-import');

  self::assertSame('Bearer test-token', $transport->getRequests()[0]->getHeader('Authorization'));
  ```

## What goes over the wire

`POST {SONAR_URL}/intake/v1/pulse/{check}` with `Authorization: Bearer <token>` and a JSON body: `id` (a UUID the
client generates), `ranAt` (ISO 8601 with offset, set when the job ran), `status`, and optionally `count`,
`duration`, `values` and `log`. The check name is URL-encoded.

## Development

```bash
make ci   # PHPUnit on PHP 7.2 and 8.5, PHPStan and php-cs-fixer, all in throwaway docker containers
```

PHPUnit runs in the real PHP 7.2 and PHP 8.5 images, with the PHPUnit version that fits each. The transport tests
start PHP's built-in web server on a free local port.

## License

MIT, see [LICENSE](LICENSE).
