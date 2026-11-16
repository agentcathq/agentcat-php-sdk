<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Internal\Delivery\DeferredWork;
use AgentCat\Internal\Delivery\Outstanding;

/** Records what the queue does with it; `run()` can run a closure (post a request) or throw. */
final class RecordingDeferredWork implements DeferredWork
{
    /** @var list<float> the `$secondsLeft` of every `run()` */
    public array $runs = [];

    /** @var list<string> the `$reason` of every `drop()` */
    public array $drops = [];

    /** What `run()` leaves on its way; null: nothing. */
    public ?Outstanding $leaves = null;

    /** @param (\Closure(float): void)|null $onRun */
    public function __construct(
        private readonly ?\Closure $onRun = null,
        private readonly ?\Throwable $failure = null,
    ) {}

    #[\Override]
    public function run(float $secondsLeft): ?Outstanding
    {
        $this->runs[] = $secondsLeft;
        if ($this->onRun instanceof \Closure) {
            ($this->onRun)($secondsLeft);
        }
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        return $this->leaves;
    }

    #[\Override]
    public function drop(string $reason): void
    {
        $this->drops[] = $reason;
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }
    }
}
