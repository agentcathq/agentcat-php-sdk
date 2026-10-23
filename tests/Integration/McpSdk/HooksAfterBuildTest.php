<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Tests\Support\McpSdk\Entry;

/** HooksTestCase with AgentCat tracking the Builder after build(). */
final class HooksAfterBuildTest extends HooksTestCase
{
    #[\Override]
    protected static function entry(): Entry
    {
        return Entry::BuilderAfterBuild;
    }
}
