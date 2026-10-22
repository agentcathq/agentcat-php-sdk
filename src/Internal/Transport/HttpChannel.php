<?php

declare(strict_types=1);

namespace AgentCat\Internal\Transport;

/**
 * Sends the HTTP requests of exporters and diagnostics, each once, beside the AgentCat event deliveries. No method
 * throws; only `waitForActivity()` blocks.
 *
 * @internal
 */
interface HttpChannel
{
    /**
     * Queues `$request`. Never blocks or throws. `$onDone` runs once, in a later `pump()`, unless the request is
     * dropped (ext-curl is missing, or the request was the oldest of a full queue).
     *
     * @param \Closure(HttpResponse): void $onDone
     */
    public function post(HttpRequest $request, \Closure $onDone): void;

    /** Starts waiting requests, collects finished ones and runs their callbacks. Never blocks or throws. */
    public function pump(): void;

    /** Requests waiting to start plus requests in transfer; without the background ones when `$includeBackground` is false. */
    public function pending(bool $includeBackground = true): int;

    /** Blocks until a transfer can progress or `$seconds` pass, at most 1 s. Does no work. */
    public function waitForActivity(float $seconds): void;
}
