<?php

declare(strict_types=1);

namespace Bannerstop\Sonar;

/**
 * Runs a task once the response has gone out: a shutdown function that first lets the web server finish the
 * request (php-fpm and LiteSpeed), or flushes the output where the SAPI cannot (mod_php). On the command line the
 * task simply runs at shutdown.
 *
 * @internal
 */
final class AfterResponse
{
    /** @var callable(callable): void */
    private $register;

    /** @var callable(): void */
    private $finish;

    /**
     * @param callable(callable): void|null $register how a task is scheduled for shutdown; for tests
     * @param callable(): void|null         $finish   how the response is closed; for tests
     */
    public function __construct(?callable $register = null, ?callable $finish = null)
    {
        $this->register = $register ?? 'register_shutdown_function';
        $this->finish = $finish ?? [self::class, 'finishRequest'];
    }

    public function defer(callable $task, bool $web): void
    {
        call_user_func($this->register, function () use ($task, $web): void {
            try {
                if ($web) {
                    ignore_user_abort(true);
                    call_user_func($this->finish);
                }
                $task();
            } catch (\Throwable $e) {
                // monitoring never reaches the user
            }
        });
    }

    public static function finishRequest(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();

            return;
        }
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();

            return;
        }
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        flush();
    }
}
