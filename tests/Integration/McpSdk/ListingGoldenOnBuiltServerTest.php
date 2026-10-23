<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Tests\Support\McpSdk\Entry;

/** ListingGoldenTestCase with AgentCat tracking the server built from the Builder. */
final class ListingGoldenOnBuiltServerTest extends ListingGoldenTestCase
{
    #[\Override]
    protected static function entry(): Entry
    {
        return Entry::BuiltServer;
    }
}
