<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Tests\Support\McpSdk\Entry;

/** ServerInfoTestCase with AgentCat tracking the Builder after build(). */
final class ServerInfoAfterBuildTest extends ServerInfoTestCase
{
    #[\Override]
    protected static function entry(): Entry
    {
        return Entry::BuilderAfterBuild;
    }
}
