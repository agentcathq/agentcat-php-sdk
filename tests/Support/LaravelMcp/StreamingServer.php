<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use AgentCat\Tests\Support\LaravelMcp\Tools\FailingStreamTool;
use AgentCat\Tests\Support\LaravelMcp\Tools\StreamingTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Contracts\Transport;

/** `streaming-server` with two streamed tools: `stream` and `stream-fails`. */
#[Name('streaming-server')]
#[Version('1.0.0')]
final class StreamingServer extends Server
{
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->tools = [StreamingTool::class, FailingStreamTool::class];
    }
}
