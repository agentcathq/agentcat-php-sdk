<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;

/** An explicit mcp/sdk tool handler that records the arguments of every call. It answers `ok` by default. */
final class RecordingTool implements ToolHandlerInterface
{
    /** @var list<array<string, mixed>> the arguments of each call, in order */
    public array $calls = [];

    /** @param (\Closure(array<string, mixed>): mixed)|null $respond builds the answer from the arguments */
    public function __construct(private readonly ?\Closure $respond = null) {}

    #[\Override]
    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        $this->calls[] = $arguments;

        return $this->respond instanceof \Closure ? ($this->respond)($arguments) : 'ok';
    }
}
