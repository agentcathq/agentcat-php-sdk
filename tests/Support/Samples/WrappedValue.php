<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Samples;

/** Serializes to the value it wraps; chained instances form one expansion step per wrapper. */
final readonly class WrappedValue implements \JsonSerializable
{
    public function __construct(private mixed $inner) {}

    /** `$levels` wrappers around `$leaf`. */
    public static function chain(int $levels, mixed $leaf = 'leaf'): mixed
    {
        $node = $leaf;
        for ($i = 0; $i < $levels; ++$i) {
            $node = new self($node);
        }

        return $node;
    }

    #[\Override]
    public function jsonSerialize(): mixed
    {
        return $this->inner;
    }
}
