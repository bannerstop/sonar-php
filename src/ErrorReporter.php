<?php

declare(strict_types=1);

namespace Bannerstop\Sonar;

use Bannerstop\Sonar\Transport\DefaultTransport;
use Bannerstop\Sonar\Transport\Transport;

/**
 * Reports errors, exceptions and log messages to the error intake of sonar (`POST /intake/v1/errors`) and can
 * register itself as the handler for uncaught exceptions, PHP errors and fatal errors at shutdown. Nothing here
 * throws into the application, and an error raised while reporting is dropped.
 *
 * What is sent: the exception class, message and a trace of file, line, function and class (no arguments, no
 * local variables), the causes (`previous`) in the context, level, environment, code version, the server name and,
 * in a web request, only the method and the URL without its query string. No body, cookies, headers or IP address.
 * Context fields whose name looks like a secret or personal data are scrubbed, and obvious secrets in message
 * texts are redacted (see Scrubber); sonar scrubs again on arrival.
 *
 * In a web request items go out after the response (like the runtime report), on the command line at once.
 * An item sonar cannot take now is kept in a small on-disk buffer and resent with a later report. A local rate
 * guard (`maxItems` per process, the same error at most 3 times) stops loops, and the answer 429 holds back all
 * sending until its Retry-After is over.
 *
 * Options (all optional):
 *
 * - connectTimeout (float, 1.0) and timeout (float, 2.0): seconds, for the default transports
 * - transport (Transport): replaces the default; it owns its timeouts
 * - bufferDir (string): defaults to sys_get_temp_dir()/sonar-php/errors-<hash of url and token>
 * - bufferLimit (int, 100): the most items kept; the oldest are dropped beyond it
 * - codeVersion (string): the revision the application runs, e.g. a Git SHA
 * - scrub (list of strings): more field names to scrub, on top of the built-in list
 * - maxItems (int, 20): the most items one process sends
 * - errorLevel (int): the PHP error levels `register()` reports, by default warnings and worse (no notices, no deprecations)
 * - serverName (string): defaults to gethostname()
 * - sapi ("web" or "cli"), server (array, $_SERVER), afterResponse (callable(callable, bool): void), clock: for tests
 */
final class ErrorReporter
{
    private const OPTIONS = ['connectTimeout', 'timeout', 'transport', 'bufferDir', 'bufferLimit', 'codeVersion', 'scrub', 'maxItems', 'errorLevel', 'serverName', 'sapi', 'server', 'afterResponse', 'clock'];
    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
    private const E_STRICT_LEVEL = 2048; // E_STRICT is deprecated as a constant since PHP 8.4
    private const MAX_FRAMES = 100;
    private const MAX_CAUSES = 10;
    private const MAX_BODY_BYTES = 200000; // sonar refuses more than 256 KB
    private const SAME_ERROR = 3;
    private const OUTAGE_SECONDS = 60;
    private const MAX_RETRY_AFTER = 3600;
    private const FLUSH_LIMIT = 10;
    private const FLUSH_SECONDS = 1.0;
    private const RESERVED_BYTES = 262144;
    private const ERROR_LABELS = [
        E_ERROR => 'E_ERROR', E_WARNING => 'E_WARNING', E_PARSE => 'E_PARSE', E_NOTICE => 'E_NOTICE',
        E_CORE_ERROR => 'E_CORE_ERROR', E_CORE_WARNING => 'E_CORE_WARNING', E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING', E_USER_ERROR => 'E_USER_ERROR', E_USER_WARNING => 'E_USER_WARNING',
        E_USER_NOTICE => 'E_USER_NOTICE', E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR', E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED', self::E_STRICT_LEVEL => 'E_STRICT',
    ];

    /** @var string */
    private $url;

    /** @var string */
    private $token;

    /** @var string */
    private $environment;

    /** @var string|null */
    private $codeVersion;

    /** @var Transport */
    private $transport;

    /** @var Buffer */
    private $buffer;

    /** @var Scrubber */
    private $scrubber;

    /** @var string */
    private $sapi;

    /** @var array<string, mixed>|null */
    private $server;

    /** @var string|null */
    private $serverName;

    /** @var int */
    private $maxItems;

    /** @var int */
    private $errorLevel;

    /** @var callable(callable, bool): void */
    private $afterResponse;

    /** @var callable(): \DateTimeImmutable */
    private $clock;

    /** @var list<array<string, mixed>> */
    private $queue = [];

    /** @var bool */
    private $scheduled = false;

    /** @var bool */
    private $busy = false;

    /** @var int */
    private $accepted = 0;

    /** @var array<string, int> */
    private $seen = [];

    /** @var bool */
    private $loggedGuard = false;

    /** @var bool */
    private $registered = false;

    /** @var bool */
    private $uncaught = false;

    /** @var callable|null */
    private $previousExceptionHandler;

    /** @var callable|null */
    private $previousErrorHandler;

    /** @var string */
    private $reserved = '';

    /**
     * @param string               $url         the base URL of sonar, e.g. https://sonar.example.com
     * @param string               $token       the token of the Project (`sonar_pls_...`)
     * @param string               $environment e.g. production; lower case letters, digits, `_` and `-`, at most 32
     * @param array<string, mixed> $options
     *
     * @throws \InvalidArgumentException for an empty URL or token, an environment sonar would refuse and an unknown option
     */
    public function __construct(string $url, string $token, string $environment, array $options = [])
    {
        if ('' === trim($url) || '' === $token) {
            throw new \InvalidArgumentException('Both the URL and the token of sonar are needed.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $environment)) {
            throw new \InvalidArgumentException('The environment has to be lower case letters, digits, "_" and "-", at most 32 characters, e.g. "production".');
        }
        $unknown = array_diff(array_keys($options), self::OPTIONS);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(sprintf('Unknown option(s): %s.', implode(', ', $unknown)));
        }
        $this->url = rtrim(trim($url), '/');
        $this->token = $token;
        $this->environment = $environment;
        $this->codeVersion = isset($options['codeVersion']) ? Scrubber::cut((string) $options['codeVersion'], 100) : null;
        $this->clock = $options['clock'] ?? static function (): \DateTimeImmutable {
            return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        };
        $this->transport = $options['transport'] ?? DefaultTransport::create((float) ($options['connectTimeout'] ?? 1.0), (float) ($options['timeout'] ?? 2.0));
        $this->buffer = new Buffer(
            $options['bufferDir'] ?? sys_get_temp_dir().'/sonar-php/errors-'.substr(hash('sha256', $this->url."\n".$this->token), 0, 16),
            (int) ($options['bufferLimit'] ?? 100),
            $this->clock,
            'occurredAt'
        );
        $this->scrubber = new Scrubber(array_values(array_map('strval', $options['scrub'] ?? [])));
        $this->sapi = $options['sapi'] ?? ('cli' === PHP_SAPI || 'phpdbg' === PHP_SAPI ? 'cli' : 'web');
        $this->server = $options['server'] ?? null;
        $this->serverName = $options['serverName'] ?? null;
        $this->maxItems = (int) ($options['maxItems'] ?? 20);
        $this->errorLevel = (int) ($options['errorLevel'] ?? (E_ALL & ~E_NOTICE & ~E_USER_NOTICE & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~self::E_STRICT_LEVEL));
        $this->afterResponse = $options['afterResponse'] ?? [new AfterResponse(), 'defer'];
    }

    /**
     * Reports an exception (type `error`), with its causes in the context. A `fingerprint` in the context is the
     * application's own grouping key.
     *
     * @param array<string, mixed> $context
     * @param string               $level   `error` or `critical`
     */
    public function reportThrowable(\Throwable $e, array $context = [], string $level = 'error'): void
    {
        try {
            if ($this->busy) {
                return;
            }
            $causes = [];
            for ($previous = $e->getPrevious(); null !== $previous && count($causes) < self::MAX_CAUSES; $previous = $previous->getPrevious()) {
                $causes[] = $this->exception($previous);
            }
            $fingerprint = $this->takeFingerprint($context);
            if ([] !== $causes) {
                $context['previous'] = $causes;
            }
            $item = $this->item('error', 'critical' === $level ? 'critical' : 'error', $context, $fingerprint);
            $item['exception'] = $this->exception($e);
            $top = $item['exception']['trace'][0] ?? [];
            $this->submit($item, implode('|', ['error', $item['exception']['class'], $item['exception']['message'], $top['file'] ?? '', $top['line'] ?? '']));
        } catch (\Throwable $dropped) {
            // monitoring never breaks the application
        }
    }

    /**
     * Reports a log message (type `log`).
     *
     * @param string               $level   debug, info, notice, warning, error or critical (PSR-3 alert and emergency count as critical)
     * @param array<string, mixed> $context
     */
    public function reportMessage(string $level, string $message, array $context = []): void
    {
        try {
            if ($this->busy) {
                return;
            }
            $level = strtolower($level);
            $level = in_array($level, ['alert', 'emergency'], true) ? 'critical' : (in_array($level, self::LEVELS, true) ? $level : 'error');
            $fingerprint = $this->takeFingerprint($context);
            $item = $this->item('log', $level, $context, $fingerprint);
            $item['message'] = $this->scrubber->text($message, 2000);
            $this->submit($item, implode('|', ['log', $level, $item['message']]));
        } catch (\Throwable $dropped) {
            // monitoring never breaks the application
        }
    }

    /**
     * Registers the handlers for uncaught exceptions, PHP errors and fatal errors. The handlers that were set
     * before still run afterwards. Call it once, early in the bootstrap.
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;
        $this->reserved = str_repeat('x', self::RESERVED_BYTES);
        $this->previousExceptionHandler = set_exception_handler([$this, 'handleException']);
        $this->previousErrorHandler = set_error_handler([$this, 'handleError']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * Puts the previous handlers back. Only right while this reporter's handlers are the last ones registered.
     */
    public function unregister(): void
    {
        if (!$this->registered) {
            return;
        }
        $this->registered = false;
        restore_exception_handler();
        restore_error_handler();
    }

    /**
     * @internal the registered exception handler
     */
    public function handleException(\Throwable $e): void
    {
        $this->uncaught = true;
        $this->reportThrowable($e);
        if (null !== $this->previousExceptionHandler) {
            call_user_func($this->previousExceptionHandler, $e);

            return;
        }
        // Rethrowing is not reliable (PHP 8.5 calls this handler again for it), so end as PHP would without a handler.
        $text = 'PHP Fatal error:  Uncaught '.$e."\n  thrown in ".$e->getFile().' on line '.$e->getLine();
        if ('web' === $this->sapi) {
            error_log($text);
            if (!headers_sent()) {
                http_response_code(500);
            }
        } elseif (defined('STDERR')) {
            fwrite(STDERR, $text."\n");
        }
        exit(255);
    }

    /**
     * @internal the registered error handler; returns false, so PHP's own handling (or the previous handler) goes on
     */
    public function handleError(int $level, string $message, string $file = '', int $line = 0): bool
    {
        if ($this->busy) {
            return true; // an error raised while reporting is dropped
        }
        try {
            if ((error_reporting() & $level) && ($this->errorLevel & $level)) {
                $label = self::ERROR_LABELS[$level] ?? 'E_UNKNOWN';
                $this->reportMessage(self::levelOf($level), $label.': '.$message, ['file' => $file, 'line' => $line]);
            }
        } catch (\Throwable $dropped) {
            // monitoring never breaks the application
        }

        // like PHP: only a strict false lets the standard handling go on
        return null !== $this->previousErrorHandler ? false !== call_user_func($this->previousErrorHandler, $level, $message, $file, $line) : false;
    }

    /**
     * @internal the registered shutdown function
     */
    public function handleShutdown(): void
    {
        if (!$this->registered) {
            return;
        }
        if ('' !== $this->reserved) {
            $this->reserved = ''; // room to work after an out-of-memory error
        }
        $error = error_get_last();
        if (!$this->uncaught && null !== $error && (self::FATAL & $error['type'])) {
            $this->reportFatal($error);
        }
    }

    /**
     * @internal
     *
     * @param array{type: int, message: string, file: string, line: int} $error as error_get_last() returns it
     */
    public function reportFatal(array $error): void
    {
        try {
            if ($this->busy) {
                return;
            }
            $item = $this->item('error', 'critical', [], null);
            $item['exception'] = [
                'class' => self::ERROR_LABELS[$error['type']] ?? 'E_ERROR',
                'message' => $this->scrubber->text($error['message'], 2000),
                'trace' => [self::frame($error['file'], $error['line'])],
            ];
            $this->submit($item, implode('|', ['fatal', $item['exception']['message'], $error['file'], $error['line']]));
        } catch (\Throwable $dropped) {
            // monitoring never breaks the application
        }
    }

    private static function levelOf(int $phpLevel): string
    {
        if ($phpLevel & (E_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) {
            return 'error';
        }
        if ($phpLevel & (E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING)) {
            return 'warning';
        }

        return $phpLevel & (E_NOTICE | E_USER_NOTICE) ? 'notice' : 'info';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function takeFingerprint(array &$context): ?string
    {
        $fingerprint = $context['fingerprint'] ?? null;
        unset($context['fingerprint']);

        return is_string($fingerprint) && '' !== $fingerprint ? Scrubber::cut($fingerprint, 200) : null;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function item(string $type, string $level, array $context, ?string $fingerprint): array
    {
        $item = [
            'type' => $type,
            'environment' => $this->environment,
            'occurredAt' => $this->now()->format('Y-m-d\TH:i:s.uP'),
            'level' => $level,
        ];
        if (null !== $this->codeVersion) {
            $item['codeVersion'] = $this->codeVersion;
        }
        if (null !== $fingerprint) {
            $item['fingerprint'] = $fingerprint;
        }
        $request = $this->request();
        if ([] !== $request) {
            $item['request'] = $request;
        }
        $item['context'] = $this->scrubber->value(array_merge(['server' => $this->serverName ?? (string) gethostname()], $context));

        return $item;
    }

    /**
     * @return array<string, string> method and URL without the query string; nothing on the command line
     */
    private function request(): array
    {
        $server = $this->server ?? $_SERVER;
        $method = $server['REQUEST_METHOD'] ?? null;
        if (!is_string($method) || '' === $method) {
            return [];
        }
        $request = ['method' => Scrubber::cut($method, 16)];
        $host = $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? null;
        $path = is_string($server['REQUEST_URI'] ?? null) ? parse_url($server['REQUEST_URI'], PHP_URL_PATH) : null;
        if (is_string($host) && preg_match('/^[A-Za-z0-9.:\[\]-]{1,255}$/', $host) && is_string($path)) {
            $https = isset($server['HTTPS']) && '' !== $server['HTTPS'] && 'off' !== $server['HTTPS'];
            $request['url'] = Scrubber::cut(($https ? 'https' : 'http').'://'.$host.$path, 2000);
        }

        return $request;
    }

    /**
     * @return array{class: string, message: string, trace: list<array<string, mixed>>}
     */
    private function exception(\Throwable $e): array
    {
        $trace = [self::frame($e->getFile(), $e->getLine())];
        foreach ($e->getTrace() as $frame) {
            $trace[] = self::frame(is_string($frame['file'] ?? null) ? $frame['file'] : null, (int) ($frame['line'] ?? 0), $frame['function'], $frame['class'] ?? null);
        }

        return [
            'class' => Scrubber::cut(Scrubber::className($e), 255),
            'message' => $this->scrubber->text($e->getMessage(), 2000),
            'trace' => array_slice($trace, 0, self::MAX_FRAMES),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function frame(?string $file, int $line, ?string $function = null, ?string $class = null): array
    {
        $frame = [];
        if (null !== $file) {
            $frame['file'] = Scrubber::cut($file, 500);
            $frame['line'] = max(0, $line);
        }
        if (null !== $function) {
            $frame['function'] = Scrubber::cut($function, 255);
        }
        if (null !== $class) {
            $frame['class'] = Scrubber::cut($class, 255);
        }

        return $frame;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function submit(array $item, string $signature): void
    {
        if ($this->accepted >= $this->maxItems || ($this->seen[$signature] ?? 0) >= self::SAME_ERROR) {
            if (!$this->loggedGuard) {
                $this->loggedGuard = true;
                Log::error('The error rate guard dropped an item: too many items or the same error too often in one process.');
            }

            return;
        }
        ++$this->accepted;
        $this->seen[$signature] = ($this->seen[$signature] ?? 0) + 1;
        $this->queue[] = $item;
        if ('web' !== $this->sapi) {
            $this->flushQueue();
        } elseif (!$this->scheduled) {
            $this->scheduled = true;
            call_user_func($this->afterResponse, function (): void {
                $this->flushQueue();
            }, true);
        }
    }

    private function flushQueue(): void
    {
        $this->scheduled = false;
        $items = $this->queue;
        $this->queue = [];
        $this->busy = true;
        try {
            foreach ($items as $item) {
                $this->send($item);
            }
        } catch (\Throwable $e) {
            Log::error('Error items were dropped: '.$e->getMessage());
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Resends the buffer within a small budget, then sends the item; what sonar cannot take now is buffered.
     *
     * @param array<string, mixed> $item
     */
    private function send(array $item): void
    {
        try {
            $item = $this->fit($item);
            $this->buffer->flush(function (string $check, string $json): PulseResult {
                return $this->deliver($json);
            }, self::FLUSH_LIMIT, self::FLUSH_SECONDS);
            $result = $this->deliver(Json::encode($item));
            if ($result->isBuffered()) {
                $occurredAt = date_create_immutable((string) $item['occurredAt']);
                $this->buffer->add('error', $item, false === $occurredAt ? $this->now() : $occurredAt);
            }
        } catch (\Throwable $e) {
            Log::error('An error item was dropped, it could not be sent or buffered: '.$e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed> the item, without its context when the body would be too large for sonar
     */
    private function fit(array $item): array
    {
        if (strlen(Json::encode($item)) > self::MAX_BODY_BYTES) {
            $item['context'] = ['server' => $item['context']['server'] ?? '', 'contextDropped' => 'too large'];
        }

        return $item;
    }

    private function deliver(string $json): PulseResult
    {
        if ($this->buffer->backedOffUntil() > $this->buffer->now()) {
            return PulseResult::buffered(null, null, 'Sonar asked to wait or was down a moment ago.');
        }
        try {
            $response = $this->transport->send($this->url.'/intake/v1/errors', [
                'Authorization' => 'Bearer '.$this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], $json);
        } catch (\Throwable $e) {
            $this->holdBack(self::OUTAGE_SECONDS);

            return PulseResult::buffered(null, null, 'No answer from sonar: '.$e->getMessage());
        }
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return PulseResult::sent(null, $status);
        }
        $wait = RetryAfter::seconds($response->getHeader('Retry-After'), $this->buffer->now(), self::MAX_RETRY_AFTER);
        if (429 === $status) {
            // "drop this item and slow down"
            $this->holdBack($wait > 0 ? $wait : self::OUTAGE_SECONDS);

            return PulseResult::rejected(null, $status, 'Dropped, sonar is limiting this token.');
        }
        if ($status >= 500 || $status < 400 || in_array($status, [408, 425], true)) {
            $this->holdBack($wait > 0 ? $wait : self::OUTAGE_SECONDS);

            return PulseResult::buffered(null, $status, 'Sonar answered '.$status.'.');
        }
        $problem = json_decode($response->getBody());
        $title = $problem instanceof \stdClass && is_string($problem->title ?? null) ? substr($problem->title, 0, 200) : '';
        Log::error(trim(sprintf('An error item was refused and dropped: sonar answered %d %s', $status, $title)));

        return PulseResult::rejected(null, $status, 'Refused by sonar.');
    }

    private function holdBack(float $seconds): void
    {
        try {
            $this->buffer->backOffUntil($this->buffer->now() + $seconds);
        } catch (\Throwable $e) {
            Log::error('The wait before the next try could not be stored: '.$e->getMessage());
        }
    }

    private function now(): \DateTimeImmutable
    {
        return call_user_func($this->clock);
    }
}
