<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

/** Simulates member drift of an integrated library for the contract and drift tests. */
final class Members
{
    /**
     * @param class-string $class the class that declares the member
     *
     * @return \Closure(object): void that unsets the typed member, so that reading it throws an `\Error`. PHP cannot
     *                                remove a declared property; a read of one that a new library version removed
     *                                warns instead.
     */
    public static function uninitialize(string $class, string $member): \Closure
    {
        return static function (object $object) use ($class, $member): void {
            // @phpstan-ignore disallowed.method (simulates drift: PHP has no public way to unset a private property)
            $unset = \Closure::bind(static function (object $o) use ($member): void {
                unset($o->{$member}); // @phpstan-ignore property.dynamicName (the caller names the member)
            }, null, $class);
            $unset($object);
        };
    }
}
