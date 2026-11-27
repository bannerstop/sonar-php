<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests;

use Bannerstop\Sonar\ErrorReporter;
use Bannerstop\Sonar\Monolog\SonarHandler;
use Bannerstop\Sonar\Tests\Support\FakeClock;
use Bannerstop\Sonar\Tests\Support\SecretsFixture;
use Bannerstop\Sonar\Tests\Support\TempDir;
use Bannerstop\Sonar\Transport\InMemoryTransport;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The golden test: an invented environment bag with typical secrets (tests/Fixtures/secrets-golden.json, the same file sonar's
 * intake test reads) goes out through every path of the error reporter, and none of its secret values arrives at the transport.
 */
final class SecretsNeverLeaveTest extends TestCase
{
    /** @var string */
    private $dir;

    /** @var InMemoryTransport */
    private $transport;

    protected function setUp(): void
    {
        $this->dir = TempDir::create();
        $this->transport = new InMemoryTransport();
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function reporter(array $options = []): ErrorReporter
    {
        return new ErrorReporter('https://sonar.example.com', 'sonar_pls_test', 'production', $options + [
            'transport' => $this->transport,
            'bufferDir' => $this->dir.'/buffer',
            'clock' => new FakeClock(),
            'sapi' => 'cli',
            'server' => [],
            'env' => [],
            'serverName' => 'web-1',
            'maxItems' => 200,
        ]);
    }

    private function sent(): string
    {
        $bodies = [];
        foreach ($this->transport->getRequests() as $request) {
            $bodies[] = $request->getBody();
        }
        self::assertNotSame([], $bodies, 'nothing was sent');

        return implode("\n", $bodies);
    }

    /**
     * Neither the raw body nor the decoded strings (JSON escapes a slash) may hold a leak; the harmless values have to survive.
     */
    private function assertNothingLeaked(string $why): void
    {
        $raw = $this->sent();
        $decoded = '';
        foreach ($this->transport->getRequests() as $request) {
            $decoded .= "\n".var_export(json_decode($request->getBody(), true), true);
        }
        foreach (SecretsFixture::leaks() as $leak) {
            self::assertStringNotContainsString($leak, $raw, $why.': '.$leak);
            self::assertStringNotContainsString($leak, $decoded, $why.': '.$leak);
        }
    }

    private function assertHarmlessValuesSurvived(): void
    {
        $raw = $this->sent();
        foreach (SecretsFixture::keep() as $value) {
            self::assertStringContainsString($value, $raw);
        }
    }

    public function testTheFixtureHoldsEveryLeakItGuardsAgainst(): void
    {
        $input = serialize([SecretsFixture::bag(), SecretsFixture::texts()]);
        foreach (SecretsFixture::leaks() as $leak) {
            self::assertStringContainsString($leak, $input);
        }
    }

    public function testAServerBagInTheContextOfReportMessageSendsNothingSecret(): void
    {
        $this->reporter()->reportMessage('error', 'x', ['server' => SecretsFixture::bag()]);

        $this->assertNothingLeaked('reportMessage context');
        $this->assertHarmlessValuesSurvived();
    }

    public function testAServerBagInTheContextOfALogRecordSendsNothingSecret(): void
    {
        $logger = new Logger('app', [new SonarHandler($this->reporter())]);

        $logger->error('x', ['server' => SecretsFixture::bag()]);
        $logger->warning('y', ['env' => SecretsFixture::bag(), 'nested' => ['deeper' => ['server' => SecretsFixture::bag()]]]);

        $this->assertNothingLeaked('Monolog context');
        $this->assertHarmlessValuesSurvived();
    }

    public function testAnExceptionWithContextAndASecretMessageSendsNothingSecret(): void
    {
        $texts = [];
        foreach (SecretsFixture::texts() as $case) {
            $texts[] = $case['text'];
        }
        $previous = new \LogicException(implode(' | ', $texts));
        $e = new \RuntimeException(implode(' | ', $texts), 0, $previous);

        $this->reporter()->reportThrowable($e, ['server' => SecretsFixture::bag(), 'texts' => $texts]);

        $this->assertNothingLeaked('reportThrowable');
    }

    public function testAMessageWithSecretsSendsNothingSecret(): void
    {
        foreach (SecretsFixture::texts() as $case) {
            $this->reporter()->reportMessage('warning', $case['text'], ['note' => $case['text']]);
        }

        $this->assertNothingLeaked('reportMessage text');
    }

    public function testAPhpErrorAndAFatalErrorMessageSendNothingSecret(): void
    {
        $reporter = $this->reporter(['errorLevel' => E_ALL]);
        $previous = error_reporting(E_ALL);
        try {
            foreach (SecretsFixture::texts() as $case) {
                $reporter->handleError(E_USER_WARNING, $case['text'], 'file.php', 3);
                $reporter->reportFatal(['type' => E_ERROR, 'message' => $case['text'], 'file' => 'file.php', 'line' => 3]);
            }
        } finally {
            error_reporting($previous);
        }

        $this->assertNothingLeaked('error handler');
    }

    public function testTheSecretsOfTheEnvironmentNeverLeaveUnderAnyKeyOrInsideAnyText(): void
    {
        $secrets = SecretsFixture::secretBag();
        $reporter = $this->reporter(['env' => $secrets + ['APP_ENV' => 'production']]);

        foreach ($secrets as $name => $value) {
            $reporter->reportMessage('warning', 'saw '.$value.' in a message', [
                'harmless' => $value,
                'note' => ['prefix '.$value.' suffix'],
                'x'.md5($name) => 'plain',
            ]);
            $reporter->reportThrowable(new \RuntimeException('failed with '.$value), ['fingerprint' => 'fp-'.$value]);
        }

        $this->assertNothingLeaked('environment secrets');
    }

    public function testTheEnvironmentSecretsAreCollectedFromTheProcessEnvironmentByDefault(): void
    {
        putenv('INVENTED_SERVICE_PASSWORD=invented-process-env-secret');
        try {
            $reporter = new ErrorReporter('https://sonar.example.com', 'sonar_pls_test', 'production', [
                'transport' => $this->transport,
                'bufferDir' => $this->dir.'/buffer',
                'clock' => new FakeClock(),
                'sapi' => 'cli',
                'server' => [],
                'serverName' => 'web-1',
            ]);
        } finally {
            putenv('INVENTED_SERVICE_PASSWORD');
        }

        $reporter->reportMessage('warning', 'connect failed with invented-process-env-secret', ['harmless' => 'invented-process-env-secret']);

        self::assertStringNotContainsString('invented-process-env-secret', $this->sent());
    }

    public function testASecretInTheRequestUrlPathNeverLeaves(): void
    {
        $this->reporter(['server' => [
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'www.example.com',
            'REQUEST_URI' => '/reset/ghp_INVENTED0001/confirm?token=invented-bearer-credential',
        ]])->reportMessage('warning', 'reset failed');

        $this->assertNothingLeaked('request url');
        self::assertStringContainsString('www.example.com', $this->sent());
    }

    public function testASecretInTheCodeVersionAndTheTraceNeverLeaves(): void
    {
        $this->reporter(['codeVersion' => 'v1 ghp_INVENTED0001'])->reportThrowable(new \RuntimeException('x'), ['fingerprint' => 'a sk_live_INVENTED0001']);

        $this->assertNothingLeaked('code version and fingerprint');
    }
}
