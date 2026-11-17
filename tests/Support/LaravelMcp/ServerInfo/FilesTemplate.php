<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp\ServerInfo;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

/** The resource template `file:///{name}.txt`. */
final class FilesTemplate extends Resource implements HasUriTemplate
{
    public function __construct()
    {
        $this->name = 'files';
        $this->description = 'Any file';
    }

    #[\Override]
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('file:///{name}.txt');
    }

    public function handle(Request $request): Response
    {
        return Response::text('file');
    }
}
