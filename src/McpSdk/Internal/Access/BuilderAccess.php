<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal\Access;

use AgentCat\Internal\Access\TouchesPrivateMember;
use AgentCat\Internal\Support\StrictErrors;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Schema\Implementation;
use Mcp\Server\Builder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every private member of mcp/sdk that AgentCat reads (D21). Reads only: AgentCat changes the Builder through its
 * public setters. Each closure is bound to Builder, so PHPStan checks the member in Builder's scope. The accessors
 * are `@phpstan-impure`: they return the Builder's current state, which its setters and build() change.
 *
 * @internal
 */
final class BuilderAccess
{
    /**
     * @return array<mixed>|null the handlers build() assembled, AgentCat's included; the entries are not checked here
     *
     * @phpstan-impure
     */
    #[TouchesPrivateMember(class: Builder::class, member: 'parts', type: '?array')]
    public static function requestHandlers(Builder $builder): ?array
    {
        $read = \Closure::bind(static fn(Builder $b): ?array => $b->parts === null ? null : $b->parts['requestHandlers'], null, Builder::class);

        return $read($builder);
    }

    /** @phpstan-impure */
    #[TouchesPrivateMember(class: Builder::class, member: 'parts', type: '?array')]
    public static function serverInfo(Builder $builder): ?Implementation
    {
        $read = \Closure::bind(static fn(Builder $b): ?Implementation => $b->parts === null ? null : $b->parts['configuration']->serverInfo, null, Builder::class);

        return $read($builder);
    }

    /** @phpstan-impure */
    #[TouchesPrivateMember(class: Builder::class, member: 'parts', type: '?array')]
    public static function isBuilt(Builder $builder): bool
    {
        $read = \Closure::bind(static fn(Builder $b): bool => $b->parts !== null, null, Builder::class);

        return $read($builder);
    }

    /** @phpstan-impure */
    #[TouchesPrivateMember(class: Builder::class, member: 'referenceHandler', type: '?Mcp\Capability\Registry\ReferenceHandlerInterface')]
    public static function referenceHandler(Builder $builder): ?ReferenceHandlerInterface
    {
        $read = \Closure::bind(static fn(Builder $b): ?ReferenceHandlerInterface => $b->referenceHandler, null, Builder::class);

        return $read($builder);
    }

    /** @phpstan-impure */
    #[TouchesPrivateMember(class: Builder::class, member: 'container', type: '?Psr\Container\ContainerInterface')]
    public static function container(Builder $builder): ?ContainerInterface
    {
        $read = \Closure::bind(static fn(Builder $b): ?ContainerInterface => $b->container, null, Builder::class);

        return $read($builder);
    }

    /** @phpstan-impure */
    #[TouchesPrivateMember(class: Builder::class, member: 'logger', type: '?Psr\Log\LoggerInterface')]
    public static function logger(Builder $builder): ?LoggerInterface
    {
        $read = \Closure::bind(static fn(Builder $b): ?LoggerInterface => $b->logger, null, Builder::class);

        return $read($builder);
    }

    /**
     * Reads every member once with warnings as exceptions: a renamed or removed member throws here. A retyped one
     * need not: the contract tests check the declared type of each member.
     */
    public static function probe(Builder $builder): void
    {
        StrictErrors::run(static function () use ($builder): void {
            self::requestHandlers($builder);
            self::serverInfo($builder);
            self::referenceHandler($builder);
            self::container($builder);
            self::logger($builder);
        });
    }
}
