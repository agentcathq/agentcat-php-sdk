<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Samples;

final class SerializableThing implements \JsonSerializable
{
    private string $secret = 'hidden';

    public function __construct(
        private readonly bool $returnSelf = false,
        private readonly bool $throw = false,
    ) {}

    /** @return array<string, mixed>|self */
    #[\Override]
    public function jsonSerialize(): array|self
    {
        if ($this->throw) {
            throw new \RuntimeException('cannot serialize');
        }
        if ($this->returnSelf) {
            return $this;
        }

        return ['kind' => 'thing', 'size' => 2];
    }

    public function secret(): string
    {
        return $this->secret;
    }
}
