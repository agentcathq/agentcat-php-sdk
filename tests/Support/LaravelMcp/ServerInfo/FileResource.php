<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp\ServerInfo;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

/** A resource with a name and a URI. */
final class FileResource extends Resource
{
    public function __construct(string $name, string $uri, string $description = 'A file')
    {
        $this->name = $name;
        $this->uri = $uri;
        $this->description = $description;
    }

    public function handle(Request $request): Response
    {
        return Response::text($this->name);
    }
}
