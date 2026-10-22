<?php

declare(strict_types=1);

namespace AgentCat\McpSdk;

use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\ThrowableStash;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\SdkVersion;
use AgentCat\Internal\Support\ErrorText;
use AgentCat\Internal\Support\StrictErrors;
use AgentCat\McpSdk\Internal\Access\BuilderAccess;
use AgentCat\McpSdk\Internal\Access\ServerAccess;
use AgentCat\McpSdk\Internal\CallToolInterceptor;
use AgentCat\McpSdk\Internal\CapturingReferenceHandler;
use AgentCat\McpSdk\Internal\Delegates;
use AgentCat\McpSdk\Internal\Drift;
use AgentCat\McpSdk\Internal\ListToolsInterceptor;
use AgentCat\McpSdk\Internal\RequestReader;
use Mcp\Capability\Registry\Container;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Schema\Implementation;
use Mcp\Server;
use Mcp\Server\Builder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Detects and instruments mcp/sdk servers. AgentCat changes the Builder through its public setters only.
 *
 * @internal
 */
final class McpSdkAdapter
{
    public const string SUPPORT_MATRIX = 'AgentCat supports mcp/sdk 0.8.1+ (pass the Mcp\Server\Builder to AgentCat::track() before build()).';

    /** The line logged, as a warning, when mcp/sdk internals are not what AgentCat expects: AgentCat is off then. */
    public const string COMPATIBILITY_LINE = 'AgentCat SDK Compatibility: ' . self::SUPPORT_MATRIX;

    /** @phpstan-assert-if-true Builder $server */
    public static function supports(object $server): bool
    {
        return $server instanceof Builder;
    }

    /**
     * The shape of a server, for the setup and compatibility lines. Never warns or throws.
     *
     * @return list<string>
     */
    public static function signals(object $server): array
    {
        $signals = [];
        if ($server instanceof Builder) {
            $signals[] = 'mcpSdkBuilder';
        }
        if ($server instanceof Server) {
            $signals[] = 'mcpSdkServer';
        }
        try {
            if ($server instanceof Builder && StrictErrors::run(static fn(): bool => BuilderAccess::isBuilt($server))) {
                $signals[] = 'built';
            }
        } catch (\Throwable) {
            // drift is reported by inspect()
        }

        return $signals;
    }

    public static function version(): string
    {
        return SdkVersion::installed('mcp/sdk') ?? 'unknown';
    }

    /**
     * Checks that AgentCat can instrument this Builder: its private members have the expected shape, and it is not
     * built yet.
     *
     * @throws \UnexpectedValueException with the compatibility message, after logging the compatibility line
     */
    public static function inspect(Builder $builder): void
    {
        try {
            BuilderAccess::probe($builder);
        } catch (\Throwable $e) {
            throw self::incompatible(sprintf('mcp/sdk %s internals changed (%s).', self::version(), ErrorText::of($e)), $e);
        }
        if (BuilderAccess::isBuilt($builder)) {
            throw self::incompatible('the mcp/sdk Builder was already built.');
        }
    }

    /** The PSR-3 logger set on the Builder; null when none is set or it cannot be read. Never warns or throws. */
    public static function logger(Builder $builder): ?LoggerInterface
    {
        try {
            return StrictErrors::run(static fn(): ?LoggerInterface => BuilderAccess::logger($builder));
        } catch (\Throwable) {
            return null; // inspect() reports the drift
        }
    }

    /**
     * Reads the server name and version when an event is published: build() sets them. When they cannot be read
     * (`Drift`), they are unknown, and the drift is logged once per Builder.
     *
     * @return \Closure(): array{0: ?string, 1: ?string}
     */
    public static function serverInfo(Builder $builder): \Closure
    {
        return static function () use ($builder): array {
            try {
                return StrictErrors::run(static function () use ($builder): array {
                    $info = BuilderAccess::serverInfo($builder);

                    return $info instanceof Implementation ? [$info->name, $info->version] : [null, null];
                });
            } catch (\Throwable) {
                Drift::report($builder);

                return [null, null];
            }
        };
    }

    /**
     * The tracking state of a server built from a tracked Builder (ac-01-69): AgentCat's `tools/call` handler, among
     * the server's request handlers, holds it. A state found is kept for the server. Never throws.
     *
     * When the request handlers cannot be read (`Drift`), the compatibility line is logged once per server, and
     * `TrackedServers::incomplete()` turns true.
     *
     * @return TrackedServer|null null when the object is not an mcp/sdk server, its Builder was not tracked, or its
     *                            request handlers cannot be read
     */
    public static function trackedServer(object $server): ?TrackedServer
    {
        if (!$server instanceof Server) {
            return null;
        }
        try {
            $handlers = StrictErrors::run(static fn(): array => ServerAccess::requestHandlers(ServerAccess::protocol($server)));
        } catch (\Throwable) {
            Drift::report($server);
            TrackedServers::markIncomplete();

            return null;
        }
        foreach ($handlers as $handler) {
            if ($handler instanceof CallToolInterceptor) {
                $tracked = $handler->server();
                TrackedServers::add($server, $tracked);

                return $tracked;
            }
        }

        return null;
    }

    /**
     * Wraps the reference handler, then registers AgentCat's request handlers, which run before the library's own.
     * Every read comes before the first change, so a read that throws leaves the Builder untouched.
     */
    public static function install(Builder $builder, TrackedServer $server, Publisher $publisher, CallInterceptor $interceptor): void
    {
        $hasContainer = BuilderAccess::container($builder) instanceof ContainerInterface;
        $custom = BuilderAccess::referenceHandler($builder);
        $delegates = new Delegates($builder); // one per Builder, shared by AgentCat's handlers
        $stash = new ThrowableStash();
        if (!$hasContainer) {
            // The default build() would create, set here so that AgentCat's handlers and the library's share it.
            $builder->setContainer(new Container());
        }
        $inner = $custom instanceof ReferenceHandlerInterface
            ? static fn(): ReferenceHandlerInterface => $custom
            : static fn(): ReferenceHandlerInterface => new ReferenceHandler(self::container($builder));
        $builder->setReferenceHandler(new CapturingReferenceHandler($inner, $stash));
        $list = new ListToolsInterceptor($server, $delegates, $publisher);
        $builder->addRequestHandler($list);
        $builder->addRequestHandler(new CallToolInterceptor($server, $delegates, $list, $interceptor, $publisher, $stash, new RequestReader($server->httpContext)));
    }

    /**
     * The container the Builder holds at the first call, which build() also used. When it cannot be read (`Drift`),
     * the drift is logged once per Builder and a fresh default container keeps the tools working.
     */
    private static function container(Builder $builder): ?ContainerInterface
    {
        try {
            return StrictErrors::run(static fn(): ?ContainerInterface => BuilderAccess::container($builder));
        } catch (\Throwable) {
            Drift::report($builder);

            return new Container();
        }
    }

    private static function incompatible(string $reason, ?\Throwable $previous = null): \UnexpectedValueException
    {
        Log::warning(self::COMPATIBILITY_LINE);

        return new \UnexpectedValueException('AgentCat SDK compatibility error: ' . $reason . ' ' . self::SUPPORT_MATRIX, previous: $previous);
    }
}
