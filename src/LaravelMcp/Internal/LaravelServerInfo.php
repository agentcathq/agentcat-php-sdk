<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp\Internal;

use AgentCat\Internal\Engine\ToolListing;
use AgentCat\Internal\Json\JsonTree;
use AgentCat\Internal\ServerInfo\ListFailure;
use AgentCat\Internal\ServerInfo\ServerInfoSource;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * A laravel/mcp server for the server info heartbeat: the handlers that its method map names for the list requests,
 * called with the server context of one request, as laravel/mcp calls them. The context holds the name, the version,
 * the title and the instructions.
 *
 * @internal
 */
final readonly class LaravelServerInfo implements ServerInfoSource
{
    /** The JSON-RPC method of each list. */
    private const array METHODS = [
        self::TOOLS => 'tools/list',
        self::RESOURCES => 'resources/list',
        self::RESOURCE_TEMPLATES => 'resources/templates/list',
        self::PROMPTS => 'prompts/list',
    ];

    /** The id of the list requests that AgentCat sends to the handlers. */
    private const string REQUEST_ID = 'agentcat-server-info';

    /**
     * @param array<mixed> $methods the server's method map, with the `tools/list` handler that the entry named before
     *                              AgentCat's: JSON-RPC method => handler class (or container key)
     */
    public function __construct(
        private array $methods,
        private ServerContext $context,
    ) {}

    /** From the context of the request: the server object can be gone when the heartbeat runs. */
    #[\Override]
    public function server(): array
    {
        return [$this->context->implementation->name, $this->context->implementation->version];
    }

    #[\Override]
    public function title(): string
    {
        return $this->context->implementation->title ?? '';
    }

    #[\Override]
    public function instructions(): string
    {
        return $this->context->instructions;
    }

    /** The pages are the server's own tools: a client gets them with AgentCat's additions. */
    #[\Override]
    public function toolsAdvertised(): bool
    {
        return false;
    }

    /**
     * @throws ListFailure when the handler answers with a stream, a JSON-RPC error or no list
     * @throws \Throwable                what the handler throws
     */
    #[\Override]
    public function page(string $list, ?string $cursor): ?array
    {
        $method = self::METHODS[$list] ?? throw new ListFailure('the list is no list of a heartbeat');
        $handler = $this->methods[$method] ?? null;
        if (!is_string($handler)) {
            return null;
        }
        /** @var class-string<Method> $handler laravel/mcp resolves the entry with Container::make(), which takes any bound key */
        $answer = Delegate::handle($handler, new JsonRpcRequest(self::REQUEST_ID, $method, $cursor === null ? [] : ['cursor' => $cursor]), $this->context);
        if (!$answer instanceof JsonRpcResponse) {
            throw new ListFailure('the handler answered with a stream');
        }
        if (($answer->content['error'] ?? null) !== null) {
            throw new ListFailure('the handler answered with a JSON-RPC error');
        }
        $result = $answer->content['result'] ?? null;
        $items = is_array($result) ? JsonTree::of($result[$list] ?? null) : null;
        if (!is_array($result) || !is_array($items) || !array_is_list($items)) {
            throw new ListFailure('the answer of the handler has no list');
        }

        return [$items, ToolListing::nextCursor($result['nextCursor'] ?? null)];
    }
}
