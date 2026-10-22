<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Samples;

/** Serializes to `'shallow'` until call number `$lateFromCall`, then to `$late`; counts its calls. */
final class LateSerializable implements \JsonSerializable
{
    public int $calls = 0;

    public function __construct(
        private readonly mixed $late = 'late',
        private readonly int $lateFromCall = PHP_INT_MAX,
    ) {}

    #[\Override]
    public function jsonSerialize(): mixed
    {
        ++$this->calls;

        return $this->calls >= $this->lateFromCall ? $this->late : 'shallow';
    }
}
