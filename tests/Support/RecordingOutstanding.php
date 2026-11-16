<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Internal\Delivery\Outstanding;

/** What recording deferred work left on its way: a test settles it, and it records how the queue gave it up. */
final class RecordingOutstanding implements Outstanding
{
    public bool $settled = false;

    /** @var list<string> the `$reason` of every `abandon()` */
    public array $abandons = [];

    public function __construct(private readonly ?\Throwable $failure = null) {}

    #[\Override]
    public function settled(): bool
    {
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        return $this->settled;
    }

    #[\Override]
    public function abandon(string $reason): void
    {
        $this->abandons[] = $reason;
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }
    }
}
