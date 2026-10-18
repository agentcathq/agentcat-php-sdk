<?php

declare(strict_types=1);

namespace AgentCat\Internal\Support;

/**
 * Runs a closure when the last reference to it goes: at the end of the frame that holds it, also when `exit()` unwinds
 * that frame. PHP skips `finally` blocks on `exit()` (for example in a signal handler) but frees the frame's variables.
 *
 * @internal
 */
final readonly class OnRelease
{
    /** @param \Closure(): void $release must not throw */
    public function __construct(private \Closure $release) {}

    public function __destruct()
    {
        ($this->release)();
    }
}
