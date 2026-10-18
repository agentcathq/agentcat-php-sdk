<?php

declare(strict_types=1);

namespace AgentCat\McpSdk;

use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Library;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use Mcp\Server\Builder;
use Psr\Log\LoggerInterface;

/**
 * mcp/sdk as a library of the registry: each method delegates to `McpSdkAdapter`.
 *
 * @internal
 */
final readonly class McpSdkLibrary implements Library
{
    #[\Override]
    public function supports(object $server): bool
    {
        return McpSdkAdapter::supports($server);
    }

    #[\Override]
    public function sdk(): string
    {
        return 'mcp-sdk/builder';
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
        McpSdkAdapter::inspect($this->builder($server));
    }

    #[\Override]
    public function serverInfo(object $server): \Closure
    {
        return McpSdkAdapter::serverInfo($this->builder($server));
    }

    #[\Override]
    public function install(object $server, TrackedServer $state, Publisher $publisher, CallInterceptor $interceptor): void
    {
        McpSdkAdapter::install($this->builder($server), $state, $publisher, $interceptor);
    }

    #[\Override]
    public function trackedServer(object $server): ?TrackedServer
    {
        return McpSdkAdapter::trackedServer($server);
    }

    /** `track()` asks `supports()` first, so this never throws there. */
    private function builder(object $server): Builder
    {
        return $server instanceof Builder ? $server : throw new \UnexpectedValueException('Not an mcp/sdk Builder: ' . get_debug_type($server));
    }
}
