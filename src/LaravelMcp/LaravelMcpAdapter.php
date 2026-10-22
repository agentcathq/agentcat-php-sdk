<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp;

use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\HttpContext;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\ThrowableStash;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Logging\OncePerObject;
use AgentCat\Internal\SdkVersion;
use AgentCat\Internal\Support\ErrorText;
use AgentCat\Internal\Support\StrictErrors;
use AgentCat\LaravelMcp\Internal\Access\HttpTransportAccess;
use AgentCat\LaravelMcp\Internal\Access\ServerAccess;
use AgentCat\LaravelMcp\Internal\HandshakeClient;
use AgentCat\LaravelMcp\Internal\LaravelHttpContext;
use AgentCat\LaravelMcp\Internal\LaravelRequestReader;
use AgentCat\LaravelMcp\Internal\MethodKey;
use AgentCat\LaravelMcp\Internal\TrackedCallTool;
use AgentCat\LaravelMcp\Internal\TrackedInitialize;
use AgentCat\LaravelMcp\Internal\TrackedListTools;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\Transport\HttpTransport;

/**
 * Detects and instruments laravel/mcp servers. AgentCat changes a server only through its public `addMethod()`: the
 * entries it points at AgentCat's own method objects, bound in the container under per-server keys, which delegate to
 * the classes the entries named before.
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
     * not keep a finished request's server alive. `createContext()` is public code that a server can override, not a
     * protected-member read: when it throws, they are unknown, and AgentCat logs nothing.
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
                $implementation = $server->createContext()->implementation;

                return [$implementation->name, $implementation->version];
            } catch (\Throwable) {
                return [null, null];
            }
        };
    }

    /**
     * The HTTP request the server answers, read from its own transport when a call is recorded, so an Octane worker
     * records the request it serves; null on stdio and once the server is gone. Holds the server weakly, like
     * `serverInfo()`. Never throws; the call is then recorded without HTTP context:
     * - when the transport or its request cannot be read (drift), and the compatibility line is logged once per server;
     * - when Laravel cannot read the request (Symfony refuses an invalid `X-HTTP-Method-Override` header, which the
     *   client sends), and nothing is logged.
     *
     * @return \Closure(): ?HttpContext
     */
    public static function httpContext(Server $server): \Closure
    {
        $reference = \WeakReference::create($server);

        return static function () use ($reference): ?HttpContext {
            $server = $reference->get();
            $request = $server instanceof Server ? self::request($server) : null;
            try {
                return $request instanceof Request ? LaravelHttpContext::of($request) : null;
            } catch (\Throwable) {
                return null; // Laravel cannot read the request, which the client controls: this is no drift
            }
        };
    }

    /**
     * Points the server's `tools/list`, `tools/call` and `initialize` entries at AgentCat's methods, which delegate to
     * the classes the entries named. An entry that names no method class (a container key, another package's binding)
     * stays as it is. Every read comes before the first change, so a read that throws leaves the server untouched.
     */
    public static function install(Server $server, TrackedServer $state, Publisher $publisher, CallInterceptor $interceptor): void
    {
        $methods = ServerAccess::methods($server);
        $list = self::entry($methods, 'tools/list');
        $call = self::entry($methods, 'tools/call');
        $initialize = self::entry($methods, 'initialize');
        $container = Container::getInstance();
        $handshake = new HandshakeClient(); // one per server: the client of a legacy handshake, for its tool calls
        if ($list !== null) {
            self::point($server, $container, 'tools/list', new TrackedListTools($state, $list, $publisher));
        }
        if ($call !== null) {
            $reader = new LaravelRequestReader(self::httpContext($server), $handshake);
            self::point($server, $container, 'tools/call', new TrackedCallTool($state, $call, $list, $interceptor, $publisher, new ThrowableStash(), $reader));
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

    /**
     * The request of the server's HTTP transport; null on another transport, and when the transport or its request
     * cannot be read (drift), which logs the compatibility line once per server. Never throws.
     */
    public static function request(Server $server): ?Request
    {
        try {
            return StrictErrors::run(static function () use ($server): ?Request {
                $transport = ServerAccess::transport($server);

                return $transport instanceof HttpTransport ? HttpTransportAccess::request($transport) : null;
            });
        } catch (\Throwable) {
            OncePerObject::warn($server, self::COMPATIBILITY_LINE);

            return null;
        }
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
