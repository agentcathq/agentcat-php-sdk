<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use AgentCat\Tests\Support\LaravelMcp\Tools\EchoTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * A server whose `tools/call` entry names `RecordingMethod`, which each test binds in the container with the answer it
 * needs, and whose `tools/list` stays laravel/mcp's (the `echo` tool).
 */
#[Name('recording-call-server')]
#[Version('1.0.0')]
final class RecordingCallServer extends Server
{
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->tools = [EchoTool::class];
        $this->addMethod('tools/call', RecordingMethod::class);
    }
}
