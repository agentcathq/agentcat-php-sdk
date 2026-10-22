<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

use Mcp\Server\Builder;

/** Simulates mcp/sdk member drift on a Builder for the contract tests. */
final class BuilderMembers
{
    /**
     * @return \Closure(Builder): void that unsets the typed member, so that reading it throws an `\Error`. PHP cannot
     *                                remove a declared property; a read of one that a new mcp/sdk removed warns instead.
     */
    public static function uninitialize(string $member): \Closure
    {
        return static function (Builder $builder) use ($member): void {
            // @phpstan-ignore disallowed.method (simulates drift: PHP has no public way to unset a private property)
            $unset = \Closure::bind(static function (Builder $b) use ($member): void {
                unset($b->{$member}); // @phpstan-ignore property.dynamicName (the caller names the member)
            }, null, Builder::class);
            $unset($builder);
        };
    }
}
