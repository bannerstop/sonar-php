<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests;

use Bannerstop\Sonar\AfterResponse;
use PHPUnit\Framework\TestCase;

final class AfterResponseTest extends TestCase
{
    /** @var list<string> */
    private $events = [];

    /** @var list<callable> */
    private $shutdown = [];

    private function afterResponse(): AfterResponse
    {
        return new AfterResponse(
            function (callable $function): void {
                $this->shutdown[] = $function;
            },
            function (): void {
                $this->events[] = 'response finished';
            }
        );
    }

    private function shutDown(): void
    {
        foreach ($this->shutdown as $function) {
            $function();
        }
    }

    public function testAWebTaskRunsOnlyAfterTheResponseIsFinished(): void
    {
        $this->afterResponse()->defer(function (): void {
            $this->events[] = 'task';
        }, true);

        self::assertSame([], $this->events);
        $this->shutDown();
        self::assertSame(['response finished', 'task'], $this->events);
    }

    public function testACommandLineTaskRunsAtShutdownWithoutClosingAResponse(): void
    {
        $this->afterResponse()->defer(function (): void {
            $this->events[] = 'task';
        }, false);

        $this->shutDown();

        self::assertSame(['task'], $this->events);
    }

    public function testATaskThatThrowsNeverEscapesTheShutdown(): void
    {
        $this->afterResponse()->defer(function (): void {
            throw new \RuntimeException('boom');
        }, true);

        $this->shutDown();

        $this->addToAssertionCount(1);
    }
}
