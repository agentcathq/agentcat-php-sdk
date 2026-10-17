<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Internal\Delivery\QueueCompanion;

/** Records the queue's calls; `finish()` can run a closure (post a last request), `tick()` can throw. */
final class RecordingCompanion implements QueueCompanion
{
    /** @var list<string> */
    public array $calls = [];

    public bool $work = false;

    /** @param (\Closure(): void)|null $onFinish */
    public function __construct(
        private readonly ?\Closure $onFinish = null,
        private readonly ?\Throwable $tickFailure = null,
    ) {}

    #[\Override]
    public function tick(): void
    {
        $this->calls[] = 'tick';
        if ($this->tickFailure instanceof \Throwable) {
            throw $this->tickFailure;
        }
    }

    #[\Override]
    public function finish(): void
    {
        $this->calls[] = 'finish';
        if ($this->onFinish instanceof \Closure) {
            ($this->onFinish)();
        }
    }

    #[\Override]
    public function hasWork(): bool
    {
        return $this->work;
    }
}
