<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests;

use Bannerstop\Sonar\ErrorReporter;
use Bannerstop\Sonar\Tests\Support\CapturesErrorLog;
use Bannerstop\Sonar\Tests\Support\FakeClock;
use Bannerstop\Sonar\Tests\Support\TempDir;
use Bannerstop\Sonar\Transport\InMemoryTransport;
use PHPUnit\Framework\TestCase;

/**
 * What the error reporter sends to POST /intake/v1/errors, what it leaves out, and that it never hurts the host.
 */
final class ErrorReporterTest extends TestCase
{
    use CapturesErrorLog;

    /** @var string */
    private $dir;

    /** @var InMemoryTransport */
    private $transport;

    /** @var FakeClock */
    private $clock;

    /** @var list<callable> tasks the "response has gone out" has not run yet */
    private $deferred = [];

    protected function setUp(): void
    {
        $this->dir = TempDir::create();
        $this->transport = new InMemoryTransport();
        $this->clock = new FakeClock();
        $this->deferred = [];
    }

    protected function tearDown(): void
    {
        $this->releaseErrorLog();
        TempDir::remove($this->dir);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function reporter(array $options = []): ErrorReporter
    {
        return new ErrorReporter('https://sonar.example.com/', 'sonar_pls_test', 'production', $options + [
            'transport' => $this->transport,
            'bufferDir' => $this->dir.'/buffer',
            'clock' => $this->clock,
            'sapi' => 'cli',
            'server' => [],
            'serverName' => 'web-1',
        ]);
    }

    /**
     * @return array<string, mixed> the body of the n-th request
     */
    private function body(int $n = 0): array
    {
        return json_decode($this->transport->getRequests()[$n]->getBody(), true);
    }

    private function thrown(string $message = 'The payment provider did not answer'): \RuntimeException
    {
        return new \RuntimeException($message);
    }

    public function testAnExceptionIsSentAsAnErrorItemToTheIntake(): void
    {
        $this->reporter(['codeVersion' => '9fceb02'])->reportThrowable($this->thrown());

        $request = $this->transport->getRequests()[0];
        self::assertSame('https://sonar.example.com/intake/v1/errors', $request->getUrl());
        self::assertSame('Bearer sonar_pls_test', $request->getHeader('Authorization'));
        self::assertSame('application/json', $request->getHeader('Content-Type'));
        $body = $this->body();
        self::assertSame('error', $body['type']);
        self::assertSame('production', $body['environment']);
        self::assertSame('error', $body['level']);
        self::assertSame('9fceb02', $body['codeVersion']);
        self::assertSame('2026-11-18T10:00:00.000000+00:00', $body['occurredAt']);
        self::assertSame('RuntimeException', $body['exception']['class']);
        self::assertSame('The payment provider did not answer', $body['exception']['message']);
        self::assertSame('web-1', $body['context']['server']);
    }

    public function testTheTraceStartsAtTheThrowSiteAndHoldsNoArgumentsOrVariables(): void
    {
        $this->reporter()->reportThrowable($this->thrownDeep('secret-argument-value'));

        $trace = $this->body()['exception']['trace'];
        self::assertSame(__FILE__, $trace[0]['file']);
        self::assertArrayNotHasKey('function', $trace[0]);
        self::assertSame('thrownDeep', $trace[1]['function']);
        self::assertSame(self::class, $trace[1]['class']);
        foreach ($trace as $frame) {
            self::assertSame([], array_diff(array_keys($frame), ['file', 'line', 'function', 'class']));
        }
        self::assertStringNotContainsString('secret-argument-value', $this->transport->getRequests()[0]->getBody());
    }

    private function thrownDeep(string $argument): \Throwable
    {
        return new \LogicException('boom '.strlen($argument));
    }

    public function testThePreviousExceptionsAreTheCausesInTheContext(): void
    {
        $this->reporter()->reportThrowable(new \RuntimeException('outer', 0, new \InvalidArgumentException('inner')));

        $previous = $this->body()['context']['previous'];
        self::assertCount(1, $previous);
        self::assertSame('InvalidArgumentException', $previous[0]['class']);
        self::assertSame('inner', $previous[0]['message']);
        self::assertNotEmpty($previous[0]['trace']);
    }

    public function testTheTraceIsCappedAtOneHundredFrames(): void
    {
        $this->reporter()->reportThrowable($this->recursive(150));

        self::assertCount(100, $this->body()['exception']['trace']);
    }

    private function recursive(int $depth): \Throwable
    {
        return $depth > 0 ? $this->recursive($depth - 1) : new \RuntimeException('deep');
    }

    public function testALongMessageIsCutToWhatSonarTakes(): void
    {
        $this->reporter()->reportThrowable($this->thrown(str_repeat('a', 5000)));

        self::assertSame(2000, strlen($this->body()['exception']['message']));
    }

    public function testAMessageIsAnItemOfTypeLogWithItsLevel(): void
    {
        $this->reporter()->reportMessage('warning', 'Stock is low', ['sku' => 'A-1']);

        $body = $this->body();
        self::assertSame('log', $body['type']);
        self::assertSame('warning', $body['level']);
        self::assertSame('Stock is low', $body['message']);
        self::assertSame('A-1', $body['context']['sku']);
        self::assertArrayNotHasKey('exception', $body);
    }

    public function testAnUnknownLevelBecomesError(): void
    {
        $this->reporter()->reportMessage('catastrophe', 'x');

        self::assertSame('error', $this->body()['level']);
    }

    public function testTheFingerprintTravelsAndStaysOutOfTheContext(): void
    {
        $this->reporter()->reportThrowable($this->thrown(), ['fingerprint' => 'checkout-payment', 'order' => 7]);

        $body = $this->body();
        self::assertSame('checkout-payment', $body['fingerprint']);
        self::assertSame(7, $body['context']['order']);
        self::assertArrayNotHasKey('fingerprint', $body['context']);
    }

    public function testSecretAndPersonalFieldsAreScrubbedAtAnyDepthAndKeepTheirKeys(): void
    {
        $this->reporter(['scrub' => ['Order-Reference']])->reportThrowable($this->thrown(), [
            'password' => 'hunter2',
            'user' => ['E-Mail' => 'jane@example.com', 'id' => 5, 'profile' => ['Phone_Number' => '0221 123']],
            'headers' => ['Authorization' => 'Bearer abc', 'X-Api-Key' => 'k', 'Accept' => 'text/html'],
            'orderReference' => 'ORD-1',
            'note' => 'kept',
        ]);

        $context = $this->body()['context'];
        self::assertSame('[scrubbed]', $context['password']);
        self::assertSame('[scrubbed]', $context['user']['E-Mail']);
        self::assertSame(5, $context['user']['id']);
        self::assertSame('[scrubbed]', $context['user']['profile']['Phone_Number']);
        self::assertSame('[scrubbed]', $context['headers']['Authorization']);
        self::assertSame('[scrubbed]', $context['headers']['X-Api-Key']);
        self::assertSame('text/html', $context['headers']['Accept']);
        self::assertSame('[scrubbed]', $context['orderReference']);
        self::assertSame('kept', $context['note']);
    }

    public function testObviousSecretsInFreeTextAreRedacted(): void
    {
        $message = 'Call failed: Authorization: Bearer abcdef123456 for jane@example.com via mysql://app:s3cret@db.example.com/shop with password=hunter2 and key sonar_pls_abc123';
        $this->reporter()->reportThrowable($this->thrown($message));
        $this->transport->respondWith(202);
        $this->reporter()->reportMessage('error', $message);

        foreach ([$this->body(0)['exception']['message'], $this->body(1)['message']] as $text) {
            foreach (['abcdef123456', 'jane@example.com', 's3cret', 'hunter2', 'sonar_pls_abc123'] as $secret) {
                self::assertStringNotContainsString($secret, $text);
            }
            self::assertStringContainsString('Call failed', $text);
            self::assertStringContainsString('db.example.com/shop', $text);
        }
    }

    public function testAWebRequestSendsMethodAndUrlWithoutQueryBodyCookiesHeadersOrIp(): void
    {
        $this->reporter(['sapi' => 'web', 'afterResponse' => [$this, 'defer'], 'server' => [
            'REQUEST_METHOD' => 'POST',
            'HTTPS' => 'on',
            'HTTP_HOST' => 'shop.example.com',
            'REQUEST_URI' => '/checkout?token=abc&email=jane@example.com',
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_COOKIE' => 'sid=1',
            'HTTP_AUTHORIZATION' => 'Bearer abc',
        ]])->reportThrowable($this->thrown());
        $this->runDeferred();

        $body = $this->body();
        self::assertSame(['method' => 'POST', 'url' => 'https://shop.example.com/checkout'], $body['request']);
        $json = $this->transport->getRequests()[0]->getBody();
        foreach (['203.0.113.7', 'sid=1', 'abc', 'jane'] as $leak) {
            self::assertStringNotContainsString($leak, $json);
        }
    }

    public function testACommandLineItemHasNoRequestAndNoArguments(): void
    {
        $this->reporter(['server' => ['argv' => ['bin/console', '--password=hunter2']]])->reportThrowable($this->thrown());

        $body = $this->body();
        self::assertArrayNotHasKey('request', $body);
        self::assertStringNotContainsString('hunter2', $this->transport->getRequests()[0]->getBody());
    }

    public function testInAWebRequestItemsGoOutOnceAfterTheResponse(): void
    {
        $reporter = $this->reporter(['sapi' => 'web', 'afterResponse' => [$this, 'defer']]);

        $reporter->reportThrowable($this->thrown('first'));
        $reporter->reportMessage('error', 'second');

        self::assertCount(0, $this->transport->getRequests());
        self::assertCount(1, $this->deferred);
        $this->runDeferred();
        self::assertCount(2, $this->transport->getRequests());
        self::assertSame('first', $this->body(0)['exception']['message']);
        self::assertSame('second', $this->body(1)['message']);
    }

    public function defer(callable $task): void
    {
        $this->deferred[] = $task;
    }

    private function runDeferred(): void
    {
        while ([] !== $this->deferred) {
            call_user_func(array_shift($this->deferred));
        }
    }

    public function testATransportErrorIsSwallowedAndTheItemIsBufferedAndResentLater(): void
    {
        $this->transport->failWith('connection refused');
        $reporter = $this->reporter();

        $reporter->reportThrowable($this->thrown('during the outage'));
        self::assertCount(1, $this->transport->getRequests());

        $this->clock->advance(120);
        $reporter->reportThrowable($this->thrown('after the outage'));

        self::assertCount(3, $this->transport->getRequests());
        self::assertSame('during the outage', $this->body(1)['exception']['message']);
        self::assertSame('2026-11-18T10:00:00.000000+00:00', $this->body(1)['occurredAt']);
        self::assertSame('after the outage', $this->body(2)['exception']['message']);
    }

    public function testWhileSonarIsDownTheNextItemsDoNotWaitOnAnotherTimeout(): void
    {
        $this->transport->failWith('timeout');
        $reporter = $this->reporter();

        $reporter->reportThrowable($this->thrown('one'));
        $reporter->reportThrowable($this->thrown('two'));
        $reporter->reportThrowable($this->thrown('three'));

        self::assertCount(1, $this->transport->getRequests());
    }

    public function testAServerErrorBuffersTheItem(): void
    {
        $this->transport->respondWith(503);
        $reporter = $this->reporter();

        $reporter->reportThrowable($this->thrown('kept'));
        $this->clock->advance(120);
        $reporter->reportMessage('error', 'later');

        self::assertCount(3, $this->transport->getRequests());
        self::assertSame('kept', $this->body(1)['exception']['message']);
    }

    public function testARefusedItemIsDroppedAndLoggedWithoutTheToken(): void
    {
        $this->captureErrorLog($this->dir.'/error.log');
        $this->transport->respondWith(401, '{"title":"Unauthorized"}');
        $reporter = $this->reporter();

        $reporter->reportThrowable($this->thrown());
        $this->clock->advance(120);
        $reporter->reportThrowable($this->thrown('next'));

        self::assertCount(2, $this->transport->getRequests());
        self::assertStringContainsString('401', $this->capturedErrorLog());
        self::assertStringNotContainsString('sonar_pls_test', $this->capturedErrorLog());
    }

    public function test429DropsTheItemAndHoldsBackUntilRetryAfterIsOver(): void
    {
        $this->transport->respondWith(429, '{"counted":true}', ['Retry-After' => '30']);
        $reporter = $this->reporter();

        $reporter->reportThrowable($this->thrown('dropped by sonar'));
        $this->clock->advance(10);
        $reporter->reportThrowable($this->thrown('held back'));
        self::assertCount(1, $this->transport->getRequests());

        $this->clock->advance(30);
        $reporter->reportThrowable($this->thrown('after the wait'));

        $messages = array_map(function (int $n): string {
            return $this->body($n)['exception']['message'];
        }, array_keys($this->transport->getRequests()));
        self::assertSame(['dropped by sonar', 'held back', 'after the wait'], $messages);
    }

    public function testAnErrorInsideTheReporterNeverReachesTheCaller(): void
    {
        $reporter = $this->reporter(['transport' => new class () implements \Bannerstop\Sonar\Transport\Transport {
            public function send(string $url, array $headers, string $body): \Bannerstop\Sonar\Transport\TransportResponse
            {
                throw new \Error('the transport is broken');
            }
        }]);

        $reporter->reportThrowable($this->thrown());
        $reporter->reportMessage('error', 'x');

        self::assertCount(2, glob($this->dir.'/buffer/*.json') ?: []); // a Throwable from the transport counts as no answer: both items wait in the buffer
    }

    public function testReportingWhileReportingDoesNotRecurse(): void
    {
        $reporter = null;
        $transport = new class () implements \Bannerstop\Sonar\Transport\Transport {
            /** @var ErrorReporter|null */
            public $reporter;
            /** @var int */
            public $calls = 0;

            public function send(string $url, array $headers, string $body): \Bannerstop\Sonar\Transport\TransportResponse
            {
                ++$this->calls;
                $this->reporter->reportThrowable(new \RuntimeException('raised while sending'));
                $this->reporter->handleError(E_WARNING, 'a warning while sending', __FILE__, 1);

                return new \Bannerstop\Sonar\Transport\TransportResponse(202);
            }
        };
        $reporter = $this->reporter(['transport' => $transport]);
        $transport->reporter = $reporter;

        $reporter->reportThrowable($this->thrown());

        self::assertSame(1, $transport->calls);
    }

    public function testALocalRateGuardStopsALoop(): void
    {
        $this->captureErrorLog($this->dir.'/error.log');
        $reporter = $this->reporter(['maxItems' => 3]);

        for ($i = 0; $i < 10; ++$i) {
            $reporter->reportMessage('error', 'message '.$i);
        }

        self::assertCount(3, $this->transport->getRequests());
    }

    public function testTheSameErrorIsSentAtMostThreeTimesPerProcess(): void
    {
        $this->captureErrorLog($this->dir.'/error.log');
        $reporter = $this->reporter();

        for ($i = 0; $i < 10; ++$i) {
            $reporter->reportThrowable(new \RuntimeException('same'));
        }
        $reporter->reportThrowable(new \RuntimeException('different'));

        self::assertCount(4, $this->transport->getRequests());
    }

    public function testAPhpWarningIsALogItemAndTheNormalHandlingGoesOn(): void
    {
        $reporter = $this->reporter();

        $before = error_reporting(E_ALL); // PHPUnit narrows it while a test runs
        try {
            $handled = $reporter->handleError(E_WARNING, 'Undefined variable $x', '/app/src/Cart.php', 42);
        } finally {
            error_reporting($before);
        }

        self::assertFalse($handled);
        $body = $this->body();
        self::assertSame('log', $body['type']);
        self::assertSame('warning', $body['level']);
        self::assertSame('E_WARNING: Undefined variable $x', $body['message']);
        self::assertSame('/app/src/Cart.php', $body['context']['file']);
        self::assertSame(42, $body['context']['line']);
    }

    public function testAPreviousErrorHandlerStillRunsAndItsAnswerIsKept(): void
    {
        $seen = [];
        set_error_handler(static function () use (&$seen): void {
            $seen[] = 'called'; // returns nothing: PHP reads that as handled
        });
        $reporter = $this->reporter();
        $reporter->register();
        $before = error_reporting(E_ALL);
        try {
            $returned = $reporter->handleError(E_WARNING, 'w', __FILE__, 1);
        } finally {
            error_reporting($before);
            $reporter->unregister();
            restore_error_handler();
        }

        self::assertSame(['called'], $seen);
        self::assertTrue($returned);
        self::assertCount(1, $this->transport->getRequests());
    }

    public function testASilencedOrFilteredPhpErrorIsNotReported(): void
    {
        $reporter = $this->reporter(['errorLevel' => E_ALL & ~E_USER_NOTICE]);
        $before = error_reporting(0);
        try {
            $reporter->handleError(E_WARNING, 'silenced with @', __FILE__, 1);
            error_reporting(E_ALL);
            $reporter->handleError(E_USER_NOTICE, 'filtered by errorLevel', __FILE__, 1);
            $this->reporter()->handleError(E_USER_DEPRECATED, 'deprecations and notices are off by default', __FILE__, 1);
            $this->reporter()->handleError(E_USER_NOTICE, 'deprecations and notices are off by default', __FILE__, 1);
        } finally {
            error_reporting($before);
        }

        self::assertCount(0, $this->transport->getRequests());
    }

    public function testAFatalErrorAtShutdownIsCritical(): void
    {
        $this->reporter()->reportFatal(['type' => E_ERROR, 'message' => 'Allowed memory size exhausted', 'file' => '/app/src/Big.php', 'line' => 9]);

        $body = $this->body();
        self::assertSame('error', $body['type']);
        self::assertSame('critical', $body['level']);
        self::assertSame('E_ERROR', $body['exception']['class']);
        self::assertSame('Allowed memory size exhausted', $body['exception']['message']);
        self::assertSame(['file' => '/app/src/Big.php', 'line' => 9], $body['exception']['trace'][0]);
    }

    public function testTheBufferKeepsAtMostTheConfiguredNumberOfItemsAndDropsTheOldest(): void
    {
        $this->captureErrorLog($this->dir.'/error.log');
        $this->transport->failWith('down');
        $reporter = $this->reporter(['bufferLimit' => 2]);

        foreach (['one', 'two', 'three'] as $message) {
            $reporter->reportMessage('error', $message);
            $this->clock->advance(1);
        }
        $this->clock->advance(120);
        $reporter->reportMessage('error', 'four');

        $sent = array_map(function (int $n): string {
            return $this->body($n)['message'];
        }, array_keys($this->transport->getRequests()));
        self::assertSame(['one', 'two', 'three', 'four'], $sent); // the first request failed, 'one' was dropped from the buffer, the rest was resent in order
    }

    public function testInvalidUtf8InAMessageStillProducesAValidBody(): void
    {
        $this->reporter()->reportMessage('error', "broken \xB1 text");

        self::assertIsString($this->body()['message']);
    }
}
