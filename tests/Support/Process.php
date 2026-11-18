<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests\Support;

/**
 * A child process whose output is read with a deadline, so a hanging child fails the test instead of the suite.
 */
final class Process
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private $pipes = [];

    /**
     * @param array<string> $command
     * @param array<string, string> $env added to the environment of this process
     */
    public function __construct(array $command, array $env = [])
    {
        $line = implode(' ', array_map('escapeshellarg', $command));
        $process = proc_open($line, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes, null, array_merge(getenv(), $env));
        if (!is_resource($process)) {
            throw new \RuntimeException('The process did not start.');
        }
        $this->process = $process;
        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
    }

    /**
     * @return array{int, string} exit code and what the process wrote
     */
    public function wait(float $seconds = 30.0): array
    {
        $deadline = microtime(true) + $seconds;
        $output = '';
        while (true) {
            foreach ($this->pipes as $pipe) {
                $output .= (string) stream_get_contents($pipe);
            }
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                foreach ($this->pipes as $pipe) {
                    $output .= (string) stream_get_contents($pipe);
                    fclose($pipe);
                }
                proc_close($this->process);

                return [$status['exitcode'], $output];
            }
            if (microtime(true) > $deadline) {
                proc_terminate($this->process, 9);
                proc_close($this->process);

                throw new \RuntimeException('The process did not finish in time. Output so far: '.$output);
            }
            usleep(20000);
        }
    }
}
