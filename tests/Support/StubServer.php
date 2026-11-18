<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests\Support;

/**
 * A local HTTP server (PHP's built-in one) that records requests and answers with a programmed status.
 */
final class StubServer
{
    /** @var string */
    private $state;

    /** @var int */
    private $port;

    /** @var resource|null */
    private $process;

    public function __construct()
    {
        $this->state = TempDir::create();
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    public function getUrl(): string
    {
        return 'http://127.0.0.1:'.$this->port;
    }

    /**
     * @param string $mode a status code, or "sleep:<milliseconds>:<status>"
     */
    public function answer(string $mode): void
    {
        file_put_contents($this->state.'/mode', $mode);
    }

    public function start(): void
    {
        $pipes = [];
        $process = proc_open(
            implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-S', '127.0.0.1:'.$this->port, __DIR__.'/stub/router.php'])),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            __DIR__.'/stub',
            ['STUB_STATE' => $this->state, 'PHP_CLI_SERVER_WORKERS' => '4']
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('The stub server did not start.');
        }
        $this->process = $process;
        for ($i = 0; $i < 100; ++$i) {
            $socket = @fsockopen('127.0.0.1', $this->port, $code, $message, 0.1);
            if (false !== $socket) {
                fclose($socket);

                return;
            }
            usleep(50000);
        }
        throw new \RuntimeException('The stub server did not come up.');
    }

    public function stop(): void
    {
        if (null !== $this->process) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    /**
     * @return list<array{method: string, uri: string, headers: array<string, string>, body: string}> oldest first
     */
    public function getRequests(): array
    {
        $files = glob($this->state.'/requests/*.json') ?: [];
        sort($files);

        return array_map(function ($file) {
            return json_decode((string) file_get_contents($file), true);
        }, $files);
    }

    public function forgetRequests(): void
    {
        foreach (glob($this->state.'/requests/*.json') ?: [] as $file) {
            unlink($file);
        }
    }

    public function __destruct()
    {
        $this->stop();
        TempDir::remove($this->state);
    }
}
