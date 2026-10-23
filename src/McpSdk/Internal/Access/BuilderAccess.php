<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal\Access;

use AgentCat\Internal\Access\TouchesPrivateMember;
use AgentCat\Internal\Support\StrictErrors;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Schema\Implementation;
use Mcp\Server\Builder;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every private member of the mcp/sdk Builder that AgentCat touches (D21). AgentCat writes one: the list of added
 * request handlers, to put its own first. Each closure is bound to Builder, so PHPStan checks the member in Builder's
 * scope. The accessors are `@phpstan-impure`: they return the Builder's current state, which its setters and build()
 * change.
 *
 * @internal
 */
final class BuilderAccess
{
    /**
     * @return array<mixed> the request handlers added with addRequestHandler() and addRequestHandlers(), in order
     *
     * @phpstan-impure
     */
    #[TouchesPrivateMember(class: Builder::class, member: 'requestHandlers', type: 'array')]
    private static function addedRequestHandlers(Builder $builder): array
    {
        $read = \Closure::bind(static fn(Builder $b): array => $b->requestHandlers, null, Builder::class);

        return $read($builder);
    }

    /**
     * Puts `$handlers` before every request handler added so far. A handler added later comes after them, as
     * addRequestHandler() appends, so AgentCat's handlers come first wherever track() runs.
     *
     * @param list<RequestHandlerInterface<mixed>> $handlers
     */
    #[TouchesPrivateMember(class: Builder::class, member: 'requestHandlers', type: 'array')]
    public static function prependRequestHandlers(Builder $builder, array $handlers): void
    {
        $write = \Closure::bind(static function (Builder $b) use ($handlers): void {
            $b->requestHandlers = [...$handlers, ...$b->requestHandlers];
        }, null, Builder::class);
        $write($builder);
    }

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

    /**
     * @return mixed the registry that build() assembled and gave the server, or null before build()
     *
     * @phpstan-impure
     */
    #[TouchesPrivateMember(class: Builder::class, member: 'parts', type: '?array')]
    public static function registry(Builder $builder): mixed
    {
        $read = \Closure::bind(static fn(Builder $b): mixed => $b->parts === null ? null : $b->parts['registry'], null, Builder::class);

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
            self::addedRequestHandlers($builder);
            self::requestHandlers($builder);
            self::serverInfo($builder);
            self::referenceHandler($builder);
            self::container($builder);
            self::logger($builder);
        });
    }
}
