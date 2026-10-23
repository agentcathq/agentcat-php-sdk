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

    /**
     * @param class-string $class the class that declares the member
     *
     * @return \Closure(object): mixed that reads the member
     */
    public static function reader(string $class, string $member): \Closure
    {
        return static function (object $object) use ($class, $member): mixed {
            // @phpstan-ignore disallowed.method (a drift test reads the state that it changes)
            $read = \Closure::bind(static fn(object $o): mixed => $o->{$member}, null, $class); // @phpstan-ignore property.dynamicName (the caller names the member)

            return $read($object);
        };
    }

    /**
     * @param class-string $class the class that declares the member
     *
     * @return \Closure(object, mixed): void that sets the member, to put a drifted member back or to change the state
     */
    public static function writer(string $class, string $member): \Closure
    {
        return static function (object $object, mixed $value) use ($class, $member): void {
            // @phpstan-ignore disallowed.method (simulates drift: PHP has no public way to set a private property)
            $write = \Closure::bind(static function (object $o) use ($member, $value): void {
                $o->{$member} = $value; // @phpstan-ignore property.dynamicName (the caller names the member)
            }, null, $class);
            $write($object);
        };
    }
}
