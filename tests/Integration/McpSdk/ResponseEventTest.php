<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Tests\Support\McpSdk\Entry;

/** ResponseEventTestCase with AgentCat tracking the Builder before build(). */
final class ResponseEventTest extends ResponseEventTestCase
{
    #[\Override]
    protected static function entry(): Entry
    {
        return Entry::BeforeBuild;
    }
}
