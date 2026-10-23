<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Tests\Support\McpSdk\Entry;

/** CallErrorsTestCase with AgentCat tracking the server built from the Builder. */
final class CallErrorsOnBuiltServerTest extends CallErrorsTestCase
{
    #[\Override]
    protected static function entry(): Entry
    {
        return Entry::BuiltServer;
    }
}
