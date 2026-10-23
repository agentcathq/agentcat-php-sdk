<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Tests\Support\McpSdk\Entry;

/** RequestContextTestCase with AgentCat tracking the server built from the Builder. */
final class RequestContextOnBuiltServerTest extends RequestContextTestCase
{
    #[\Override]
    protected static function entry(): Entry
    {
        return Entry::BuiltServer;
    }
}
