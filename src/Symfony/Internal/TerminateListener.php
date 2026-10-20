<?php

declare(strict_types=1);

namespace AgentCat\Symfony\Internal;

use AgentCat\AgentCat;
use AgentCat\Internal\Delivery\RequestEndWait;

/**
 * Delivers the request's events on kernel.terminate (D58), after the response, and last:
 * for at most `RequestEndWait::WORKER_SECONDS` in a Symfony Runtime worker, and in one non-blocking pass under a
 * per-request SAPI, where AgentCat's shutdown function delivers within the 5 s budget after it (D47).
 *
 * @internal
 */
final class TerminateListener
{
    /** After Symfony's own `kernel.terminate` listeners and the application's. */
    public const int PRIORITY = -2048;

    public function __invoke(): void
    {
        try {
            AgentCat::client()->flush(RequestEndWait::seconds(console: false)); // kernel.terminate never runs for a command
        } catch (\Throwable) {
            // delivery never breaks the kernel's terminate phase
        }
    }
}
