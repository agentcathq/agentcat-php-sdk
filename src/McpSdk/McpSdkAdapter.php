<?php

declare(strict_types=1);

namespace AgentCat\McpSdk;

use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\ThrowableStash;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\SdkVersion;
use AgentCat\Internal\Support\ErrorText;
use AgentCat\Internal\Support\StrictErrors;
use AgentCat\McpSdk\Internal\Access\BuilderAccess;
use AgentCat\McpSdk\Internal\Access\ServerAccess;
use AgentCat\McpSdk\Internal\BuiltServer;
use AgentCat\McpSdk\Internal\CallToolInterceptor;
use AgentCat\McpSdk\Internal\Delegates;
use AgentCat\McpSdk\Internal\Drift;
use AgentCat\McpSdk\Internal\ListToolsInterceptor;
use AgentCat\McpSdk\Internal\RequestReader;
use Mcp\Schema\Implementation;
use Mcp\Server;
use Mcp\Server\Builder;
use Psr\Log\LoggerInterface;

/**
 * Detects and instruments mcp/sdk servers: a Builder before build() through its request handlers, and a Builder after
 * build() or a built server through its tool registry (`BuiltServer`).
 *
 * @internal
 */
final class McpSdkAdapter
{
    public const string SUPPORT_MATRIX = 'AgentCat supports mcp/sdk 0.8.1+ (pass the Mcp\Server\Builder or the Mcp\Server built from it to AgentCat::track()).';

    /** The line logged, as a warning, when mcp/sdk internals are not what AgentCat expects: AgentCat is off then. */
    public const string COMPATIBILITY_LINE = 'AgentCat SDK Compatibility: ' . self::SUPPORT_MATRIX;

    /** @phpstan-assert-if-true Builder|Server $server */
    public static function supports(object $server): bool
    {
        return $server instanceof Builder || $server instanceof Server;
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
     * Checks that AgentCat can instrument this Builder or server: its private members have the expected shape, and,
     * after build(), its registry and its `tools/call` handler are mcp/sdk's own.
     *
     * @throws \UnexpectedValueException with the compatibility message, after logging the compatibility line
     */
    public static function inspect(Builder|Server $server): void
    {
        try {
            $refused = StrictErrors::run(static function () use ($server): ?\UnexpectedValueException {
                if ($server instanceof Builder) {
                    BuilderAccess::probe($server);
                }
                if (!BuiltServer::applies($server)) {
                    return null;
                }
                try {
                    BuiltServer::parts($server);
                } catch (\UnexpectedValueException $e) {
                    return $e; // a registry, handlers or a reference handler that AgentCat cannot track
                }

                return null;
            });
        } catch (\Throwable $e) {
            throw self::incompatible($server, sprintf('mcp/sdk %s internals changed (%s).', self::version(), ErrorText::of($e)), $e);
        }
        if ($refused !== null) {
            throw self::incompatible($server, $refused->getMessage(), $refused);
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
     * (`Drift`), they are unknown, and the drift is logged once per Builder or server.
     *
     * @return \Closure(): array{0: ?string, 1: ?string}
     */
    public static function serverInfo(Builder|Server $server): \Closure
    {
        return static function () use ($server): array {
            try {
                return StrictErrors::run(static function () use ($server): array {
                    $info = $server instanceof Builder ? BuilderAccess::serverInfo($server) : BuiltServer::serverInfo($server);

                    return $info instanceof Implementation ? [$info->name, $info->version] : [null, null];
                });
            } catch (\Throwable) {
                Drift::report($server);

                return [null, null];
            }
        };
    }

    /**
     * The tracking state of a server built from a Builder tracked before build() (ac-01-69): AgentCat's `tools/call`
     * handler, among the server's request handlers, holds it. For a Builder or a server tracked after build(), its
     * registry holds it (`BuiltServer`). A state found for a server is kept for it. Never throws.
     *
     * When the members of a server cannot be read (`Drift`), the compatibility line is logged once per server, and
     * `TrackedServers::incomplete()` turns true. For a Builder, `inspect()` reports it.
     *
     * @return TrackedServer|null null when the object is not a built mcp/sdk server or a built Builder, when it was not
     *                            tracked, or when its members cannot be read
     */
    public static function trackedServer(object $server): ?TrackedServer
    {
        if (!$server instanceof Server && !$server instanceof Builder) {
            return null;
        }
        try {
            $tracked = StrictErrors::run(static function () use ($server): ?TrackedServer {
                if ($server instanceof Server) {
                    foreach (ServerAccess::requestHandlers(ServerAccess::protocol($server)) as $handler) {
                        if ($handler instanceof CallToolInterceptor) {
                            return $handler->server();
                        }
                    }
                }

                return BuiltServer::trackedServer($server); // null for a Builder before build(): TrackedServers knows it
            });
        } catch (\Throwable) {
            if ($server instanceof Builder) {
                return null; // track() goes on to inspect(), which reports it with the Builder's logger
            }
            Drift::report($server);
            TrackedServers::markIncomplete();

            return null;
        }
        if ($tracked instanceof TrackedServer && $server instanceof Server) {
            TrackedServers::add($server, $tracked);
        }

        return $tracked;
    }

    /**
     * Before build(), puts AgentCat's request handlers before every handler of the Builder, so they answer first
     * wherever track() runs: one write, which a member that cannot be read stops. After build(), instruments the
     * registry (`BuiltServer`), whose reads all come before its first change.
     */
    public static function install(Builder|Server $builder, TrackedServer $server, Publisher $publisher, CallInterceptor $interceptor): void
    {
        if ($builder instanceof Server || BuiltServer::applies($builder)) {
            [$registry, $library] = BuiltServer::parts($builder);
            BuiltServer::install($registry, $library, $server, $publisher, $interceptor);

            return;
        }
        $delegates = new Delegates($builder); // one per Builder, shared by AgentCat's handlers
        $list = new ListToolsInterceptor($server, $delegates, $publisher);
        $call = new CallToolInterceptor($server, $delegates, $list, $interceptor, $publisher, new ThrowableStash(), new RequestReader($server->httpContext));
        BuilderAccess::prependRequestHandlers($builder, [$list, $call]);
    }

    /** Logs the compatibility line once per Builder or server (`Drift` may have logged it already). */
    private static function incompatible(Builder|Server $server, string $reason, ?\Throwable $previous = null): \UnexpectedValueException
    {
        Drift::report($server);

        return new \UnexpectedValueException('AgentCat SDK compatibility error: ' . $reason . ' ' . self::SUPPORT_MATRIX, previous: $previous);
    }
}
