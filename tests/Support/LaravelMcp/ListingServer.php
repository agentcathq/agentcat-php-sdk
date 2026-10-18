<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use AgentCat\Tests\Support\LaravelMcp\Tools\EchoTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * A server with its own `tools/list` handler, like a customer's: the entry names `RecordingMethod`, which each test
 * binds in the container with the answer it needs. The constructor sets the entry before the container's
 * `afterResolving` callbacks run, like a `$methods` default.
 */
#[Name('listing-server')]
#[Version('1.0.0')]
final class ListingServer extends Server
{
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->tools = [EchoTool::class];
        $this->addMethod('tools/list', RecordingMethod::class);
    }
}
