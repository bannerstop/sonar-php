# Changelog

## 1.2.0

- New: error reporting to sonar's error intake (`POST /intake/v1/errors`). `Sonar::registerErrorHandler()` reports
  uncaught exceptions, PHP errors and fatal errors; `Sonar::reportException()` and `Sonar::reportMessage()` report on
  demand; `Monolog\SonarHandler` (Monolog 2 and 3, optional) reports log records above a level threshold. Context
  fields are scrubbed by name and obvious secrets in messages are redacted before anything is sent; no local variables,
  arguments, bodies, cookies, headers or IP addresses. Sent after the response, with a 1 s / 2 s timeout, an on-disk
  buffer for outages, a local rate guard and `Retry-After` handling; nothing throws. README: "Moving from the Rollbar SDK".
- `monolog/monolog` is a suggested package, a dev dependency only. PHP floor unchanged (7.2).

## 1.1.0

- New: `Sonar::reportRuntime()` (and `RuntimeReporter`) tells sonar which PHP the application runs with: the full PHP
  version, web or cli, the package version, for one Service (`POST /intake/v1/runtime`). A small marker file makes it
  report after a deploy and then once a day per SAPI; in a web request it is sent after the response has gone out,
  with a 1 s / 2 s timeout. Failures are swallowed and logged once a day. PHP floor unchanged (7.2).

## 1.0.1

- README: `run()` resends the buffer before the job, not after it. The code was right; only the docs were wrong.

## 1.0.0

- First release: `Sonar::pulse()`, `Sonar::run()`, the on-disk buffer, `sonar-flush`, and the cURL, stream, PSR-18
  and in-memory transports.
