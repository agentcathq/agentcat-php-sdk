<?php

declare(strict_types=1);

namespace AgentCat\Laravel\Internal;

use AgentCat\AgentCat;
use AgentCat\Internal\Delivery\RequestEndWait;
use Illuminate\Contracts\Foundation\Application;

/**
 * When a Laravel application delivers (D58): after every request, in `terminating()`, for at most
 * `RequestEndWait::seconds()` — 1 s in an Octane worker, a single non-blocking pass elsewhere, where the shutdown
 * function delivers within the 5 s budget after the hook (D47) —, and for good when an Octane worker stops.
 *
 * @internal
 */
final class AfterRequest
{
    /** Named as a string: laravel/octane is not a dependency, and Laravel dispatches events by class name. */
    private const string OCTANE_WORKER_STOPPING = 'Laravel\Octane\Events\WorkerStopping';

    public static function register(Application $app): void
    {
        // The container calls the callback with the application of the request (Octane's sandbox): nothing is captured.
        $app->terminating(static function (Application $current): void {
            self::guarded(static function () use ($current): void {
                AgentCat::client()->flush(RequestEndWait::seconds(self::truthy($current->runningInConsole())));
            });
        });
        $app->make('events')->listen(self::OCTANE_WORKER_STOPPING, static function (): void {
            self::guarded(static function (): void {
                AgentCat::client()->shutdown();
            });
        });
    }

    /**
     * PHP's truthiness, which Laravel's own checks of `runningInConsole()` use. Its PHPDoc says bool, but it returns
     * APP_RUNNING_IN_CONSOLE as `Env::get()` gives it: "0", "1" or "no" stay strings.
     */
    private static function truthy(mixed $value): bool
    {
        return (bool) $value;
    }

    /**
     * Delivery never breaks the application's terminate phase, and a stopping worker must stop (D32).
     *
     * @param \Closure(): void $step
     */
    private static function guarded(\Closure $step): void
    {
        try {
            $step();
        } catch (\Throwable) {
            // nothing to do: the application goes on
        }
    }
}
