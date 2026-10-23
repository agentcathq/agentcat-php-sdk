<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Tests\Support\McpSdk\Entry;

/** CallToolTestCase with AgentCat tracking the Builder before build(). */
final class CallToolTest extends CallToolTestCase
{
    #[\Override]
    protected static function entry(): Entry
    {
        return Entry::BeforeBuild;
    }
}
