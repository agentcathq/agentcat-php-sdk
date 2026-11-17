<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use AgentCat\Internal\Engine\ToolListing;
use AgentCat\Internal\Json\JsonTree;
use AgentCat\Internal\ServerInfo\ListFailure;
use AgentCat\Internal\ServerInfo\ServerInfoSource;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListPromptsRequest;
use Mcp\Schema\Request\ListResourcesRequest;
use Mcp\Schema\Request\ListResourceTemplatesRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Server\Configuration;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * An mcp/sdk server for the server info heartbeat: its own list handlers, called with the session of one request, and
 * the configuration that build() gave its `initialize` handler. Making it reads nothing.
 *
 * @internal
 */
final readonly class McpServerInfo implements ServerInfoSource
{
    /** The id of the list requests that AgentCat sends to the handlers. */
    private const string REQUEST_ID = 'agentcat-server-info';

    /**
     * @param \Closure(Request): (RequestHandlerInterface<mixed>|null) $handler       the server's handler of a list request; null when it has none
     * @param \Closure(): ?Configuration                               $configuration the server's configuration; null when it cannot be found
     * @param bool                                                     $advertised    whether the tool list already carries AgentCat's
     *                                                                                additions (a server tracked after build())
     */
    public function __construct(
        private \Closure $handler,
        private \Closure $configuration,
        private bool $advertised,
        private SessionInterface $session,
    ) {}

    #[\Override]
    public function server(): array
    {
        $info = ($this->configuration)()?->serverInfo;

        return [$info?->name, $info?->version];
    }

    #[\Override]
    public function title(): string
    {
        return ($this->configuration)()->serverInfo->title ?? '';
    }

    #[\Override]
    public function instructions(): string
    {
        return ($this->configuration)()->instructions ?? '';
    }

    #[\Override]
    public function toolsAdvertised(): bool
    {
        return $this->advertised;
    }

    /**
     * @throws ListFailure when the handler answers with a JSON-RPC error or without the list
     * @throws \Throwable                what the handler throws
     */
    #[\Override]
    public function page(string $list, ?string $cursor): ?array
    {
        $request = (match ($list) {
            self::TOOLS => new ListToolsRequest($cursor),
            self::RESOURCES => new ListResourcesRequest($cursor),
            self::RESOURCE_TEMPLATES => new ListResourceTemplatesRequest($cursor),
            self::PROMPTS => new ListPromptsRequest($cursor),
            default => throw new ListFailure('the list is no list of a heartbeat'),
        })->withId(self::REQUEST_ID);
        $handler = ($this->handler)($request);
        if (!$handler instanceof RequestHandlerInterface) {
            return null;
        }
        $answer = $handler->handle($request, $this->session);
        if (!$answer instanceof Response) {
            throw new ListFailure('the handler answered with a JSON-RPC error');
        }
        $tree = JsonTree::of($answer->result);
        $items = $tree instanceof \stdClass ? ($tree->{$list} ?? null) : null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new ListFailure('the answer of the handler has no list');
        }

        return [$items, ToolListing::nextCursor($tree->nextCursor ?? null)];
    }
}
