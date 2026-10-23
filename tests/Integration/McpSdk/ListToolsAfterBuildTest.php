<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Tests\Support\McpSdk\Entry;

/** ListToolsTestCase with AgentCat tracking the Builder after build(). */
final class ListToolsAfterBuildTest extends ListToolsTestCase
{
    #[\Override]
    protected static function entry(): Entry
    {
        return Entry::BuilderAfterBuild;
    }
}
