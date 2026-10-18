<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp;

use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\SdkVersion;
use AgentCat\Internal\Support\ErrorText;
use AgentCat\Internal\Support\StrictErrors;
use AgentCat\LaravelMcp\Internal\Access\ServerAccess;
use AgentCat\LaravelMcp\Internal\HandshakeClient;
use AgentCat\LaravelMcp\Internal\MethodKey;
use AgentCat\LaravelMcp\Internal\TrackedInitialize;
use AgentCat\LaravelMcp\Internal\TrackedListTools;
use Illuminate\Container\Container;
use Laravel\Mcp\Schema\Implementation;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Method;

/**
 * Detects and instruments laravel/mcp servers. AgentCat changes a server only through its public `addMethod()`: the
 * entries it points at AgentCat's own method objects, bound in the container under per-server keys (plan E1 header
 * conflict 3), which delegate to the classes the entries named before.
 *
 * @internal
 */
final class LaravelMcpAdapter
{
    public const string SUPPORT_MATRIX = 'AgentCat supports laravel/mcp 1.0+ (the Laravel service provider tracks every server).';

    /** The line logged, as a warning, when laravel/mcp internals are not what AgentCat expects: AgentCat is off then. */
    public const string COMPATIBILITY_LINE = 'AgentCat SDK Compatibility: ' . self::SUPPORT_MATRIX;

    /** @phpstan-assert-if-true Server $server */
    public static function supports(object $server): bool
    {
        return $server instanceof Server;
    }

    /**
     * The shape of a server, for the setup and compatibility lines. Never warns or throws.
     *
     * @return list<string>
     */
    public static function signals(object $server): array
    {
        return $server instanceof Server ? ['laravelMcpServer'] : [];
    }

    public static function version(): string
    {
        return SdkVersion::installed('laravel/mcp') ?? 'unknown';
    }

    /**
     * Checks that AgentCat can instrument this server: the protected members it reads have the expected shape.
     *
     * @throws \UnexpectedValueException with the compatibility message, after logging the compatibility line
     */
    public static function inspect(Server $server): void
    {
        try {
            ServerAccess::probe($server);
        } catch (\Throwable $e) {
            Log::warning(self::COMPATIBILITY_LINE);

            throw new \UnexpectedValueException(sprintf('AgentCat SDK compatibility error: laravel/mcp %s internals changed (%s). %s', self::version(), ErrorText::of($e), self::SUPPORT_MATRIX), 0, $e);
        }
    }

    /**
     * The server name and version, read when an event is published. Holds the server weakly: the tracking state must
     * not keep a finished request's server alive. Unknown when they cannot be read.
     *
     * @return \Closure(): array{0: ?string, 1: ?string}
     */
    public static function serverInfo(Server $server): \Closure
    {
        $reference = \WeakReference::create($server);

        return static function () use ($reference): array {
            $server = $reference->get();
            if (!$server instanceof Server) {
                return [null, null];
            }
            try {
                $implementation = StrictErrors::run(static fn(): Implementation => $server->createContext()->implementation);

                return [$implementation->name, $implementation->version];
            } catch (\Throwable) {
                return [null, null];
            }
        };
    }

    /**
     * Points the server's `tools/list` and `initialize` entries at AgentCat's methods, which delegate to the classes
     * the entries named. An entry that names no method class (a container key, another package's binding) stays as it
     * is. Every read comes before the first change, so a read that throws leaves the server untouched.
     */
    public static function install(Server $server, TrackedServer $state, Publisher $publisher, CallInterceptor $interceptor): void
    {
        $methods = ServerAccess::methods($server);
        $list = self::entry($methods, 'tools/list');
        $initialize = self::entry($methods, 'initialize');
        $container = Container::getInstance();
        $handshake = new HandshakeClient(); // one per server: the client of a legacy handshake, for its tool calls
        if ($list !== null) {
            self::point($server, $container, 'tools/list', new TrackedListTools($state, $list, $publisher));
        }
        if ($initialize !== null) {
            self::point($server, $container, 'initialize', new TrackedInitialize($initialize, $handshake));
        }
    }

    /**
     * The method class an entry of the server's method map names; null when it names none.
     *
     * @param array<mixed> $methods
     *
     * @return class-string<Method>|null
     */
    private static function entry(array $methods, string $method): ?string
    {
        $entry = $methods[$method] ?? null;

        return is_string($entry) && is_a($entry, Method::class, true) ? $entry : null;
    }

    /** Binds AgentCat's method under this server's own key and points the server's entry at it. */
    private static function point(Server $server, Container $container, string $method, Method $handler): void
    {
        $key = MethodKey::for($server, $method);
        $container->instance($key, $handler);
        // @phpstan-ignore argument.type (laravel/mcp resolves the entry with Container::make(), which takes any bound key)
        $server->addMethod($method, $key);
    }
}
