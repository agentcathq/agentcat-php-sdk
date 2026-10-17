<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

use Mcp\Server\Builder;

/** Simulates mcp/sdk member drift on a Builder for the contract tests. */
final class BuilderMembers
{
    /** @return \Closure(Builder): void that unsets the member, as if a new mcp/sdk release had dropped it */
    public static function remove(string $member): \Closure
    {
        return static function (Builder $builder) use ($member): void {
            // @phpstan-ignore disallowed.method (simulates a removed member: PHP has no public way to unset a private property)
            $unset = \Closure::bind(static function (Builder $b) use ($member): void {
                unset($b->{$member}); // @phpstan-ignore property.dynamicName (the caller names the member)
            }, null, Builder::class);
            $unset($builder);
        };
    }
}
