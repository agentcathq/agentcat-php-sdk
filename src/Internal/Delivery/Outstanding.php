<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

/**
 * What deferred work left on its way when its run ended: a request on the side channel that has no answer yet. The
 * queue keeps it only until it is settled, and it holds only what that request and its log line need: nothing of the
 * request that deferred the work.
 *
 * @internal
 */
interface Outstanding
{
    /** True once the last answer is in, or the request failed: the queue then forgets it. */
    public function settled(): bool;

    /** The process ends while it is on its way; `$reason` says why it is given up, as the end of a log line. */
    public function abandon(string $reason): void;
}
