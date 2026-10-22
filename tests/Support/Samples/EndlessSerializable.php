<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Samples;

/** Serializes to a new instance of itself, so a walker that expands it never reaches a leaf. */
final class EndlessSerializable implements \JsonSerializable
{
    #[\Override]
    public function jsonSerialize(): self
    {
        return new self();
    }
}
