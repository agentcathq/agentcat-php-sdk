<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp;

use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Library;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use Laravel\Mcp\Server;
use Psr\Log\LoggerInterface;

/**
 * laravel/mcp as a library of the registry: each method delegates to `LaravelMcpAdapter`. A server carries no PSR-3
 * logger of its own (Laravel's log is the bridge's business), and AgentCat keeps no state in it.
 *
 * @internal
 */
final readonly class LaravelMcpLibrary implements Library
{
    #[\Override]
    public function supports(object $server): bool
    {
        return LaravelMcpAdapter::supports($server);
    }

    #[\Override]
    public function sdk(object $server): string
    {
        return 'laravel-mcp/server';
    }

    #[\Override]
    public function supportMatrix(): string
    {
        return LaravelMcpAdapter::SUPPORT_MATRIX;
    }

    #[\Override]
    public function signals(object $server): array
    {
        return LaravelMcpAdapter::signals($server);
    }

    #[\Override]
    public function logger(object $server): ?LoggerInterface
    {
        return null;
    }

    #[\Override]
    public function inspect(object $server): void
    {
        LaravelMcpAdapter::inspect($this->server($server));
    }

    #[\Override]
    public function serverInfo(object $server): \Closure
    {
        return LaravelMcpAdapter::serverInfo($this->server($server));
    }

    #[\Override]
    public function install(object $server, TrackedServer $state, Publisher $publisher, CallInterceptor $interceptor): void
    {
        LaravelMcpAdapter::install($this->server($server), $state, $publisher, $interceptor);
    }

    #[\Override]
    public function trackedServer(object $server): ?TrackedServer
    {
        return null;
    }

    /** `track()` asks `supports()` first, so this never throws there. */
    private function server(object $server): Server
    {
        return $server instanceof Server ? $server : throw new \UnexpectedValueException('Not a laravel/mcp Server: ' . get_debug_type($server));
    }
}
