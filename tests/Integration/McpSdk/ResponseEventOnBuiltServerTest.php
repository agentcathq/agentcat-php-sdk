<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Tests\Support\McpSdk\Entry;

/** ResponseEventTestCase with AgentCat tracking the server built from the Builder. */
final class ResponseEventOnBuiltServerTest extends ResponseEventTestCase
{
    #[\Override]
    protected static function entry(): Entry
    {
        return Entry::BuiltServer;
    }
}
