<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use AgentCat\Tests\Support\Members;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * A server whose method map AgentCat cannot read, standing in for a laravel/mcp that renamed or removed it: PHP cannot
 * remove a declared property, so the constructor leaves it uninitialized.
 */
final class DriftedServer extends Server
{
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        Members::uninitialize(Server::class, 'methods')($this);
    }
}
