<?php

declare(strict_types=1);

namespace AgentCat\McpSdk;

use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Library;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Stateless\StatelessProtocol;
use Psr\Log\LoggerInterface;

/**
 * mcp/sdk as a library of the registry: each method delegates to `McpSdkAdapter`.
 *
 * @internal
 */
final readonly class McpSdkLibrary implements Library
{
    /** What to pass instead, when the object is what `Builder::buildStateless()` returns; null for any other object. */
    public static function untrackedAdvice(object $server): ?string
    {
        return $server instanceof StatelessProtocol
            ? 'pass the Mcp\Server\Builder (or the Mcp\Server built from it) to AgentCat::publishCustomEvent(); Builder::buildStateless() returns a StatelessProtocol, which AgentCat cannot map to its Builder'
            : null;
    }

    #[\Override]
    public function supports(object $server): bool
    {
        return McpSdkAdapter::supports($server);
    }

    #[\Override]
    public function sdk(object $server): string
    {
        return $server instanceof Server ? 'mcp-sdk/server' : 'mcp-sdk/builder';
    }

    #[\Override]
    public function supportMatrix(): string
    {
        return McpSdkAdapter::SUPPORT_MATRIX;
    }

    #[\Override]
    public function signals(object $server): array
    {
        return McpSdkAdapter::signals($server);
    }

    #[\Override]
    public function logger(object $server): ?LoggerInterface
    {
        return $server instanceof Builder ? McpSdkAdapter::logger($server) : null;
    }

    #[\Override]
    public function inspect(object $server): void
    {
        McpSdkAdapter::inspect($this->mcp($server));
    }

    #[\Override]
    public function serverInfo(object $server): \Closure
    {
        return McpSdkAdapter::serverInfo($this->mcp($server));
    }

    #[\Override]
    public function install(object $server, TrackedServer $state, Publisher $publisher, CallInterceptor $interceptor): void
    {
        McpSdkAdapter::install($this->mcp($server), $state, $publisher, $interceptor);
    }

    #[\Override]
    public function trackedServer(object $server): ?TrackedServer
    {
        return McpSdkAdapter::trackedServer($server);
    }

    /** `track()` asks `supports()` first, so this never throws there. */
    private function mcp(object $server): Builder|Server
    {
        return $server instanceof Builder || $server instanceof Server ? $server : throw new \UnexpectedValueException('Not an mcp/sdk Builder or server: ' . get_debug_type($server));
    }
}
