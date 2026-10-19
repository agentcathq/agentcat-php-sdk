<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony;

/** A hook service that throws `RuntimeException: <name> ran` when it runs: the hook guard logs what it threw. */
final readonly class FailingHook
{
    public function __construct(private string $name) {}

    public function __invoke(): never
    {
        throw new \RuntimeException($this->name . ' ran');
    }
}
