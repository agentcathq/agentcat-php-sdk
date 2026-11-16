<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp\ServerInfo;

use AgentCat\Tests\Support\LaravelMcp\Tools\NamedTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * The server of the heartbeat tests: `demo-server` 1.2.3 with its instructions, three tools (`alpha`, `beta` with a
 * 2,000-character description and both schemas, `gamma`), two resources, one resource template and two prompts.
 * laravel/mcp gives a server no title.
 */
#[Name('demo-server')]
#[Version('1.2.3')]
#[Instructions('Use the demo tools.')]
final class DemoServer extends Server
{
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->tools = [new NamedTool('alpha'), new BetaTool(), new NamedTool('gamma')];
        $this->resources = [new FileResource('a', 'file:///a.txt'), new FileResource('b', 'file:///b.txt'), new FilesTemplate()];
        $this->prompts = [new GreetPrompt(), new ByePrompt()];
    }
}
