<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use AgentCat\Internal\Logging\OncePerObject;
use AgentCat\McpSdk\McpSdkAdapter;
use Mcp\Server;
use Mcp\Server\Builder;

/**
 * Runtime drift in what a Builder's build() assembled, or in a built server: mcp/sdk's internals changed, and AgentCat
 * cannot read them. The first drift on an object logs the compatibility line; later ones on the same object log
 * nothing.
 *
 * @internal
 */
final class Drift
{
    public static function report(Builder|Server $object): void
    {
        OncePerObject::warn($object, McpSdkAdapter::COMPATIBILITY_LINE);
    }
}
