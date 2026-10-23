<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Tests\Support\McpSdk\Entry;

/** CallErrorsTestCase with AgentCat tracking the Builder after build(). */
final class CallErrorsAfterBuildTest extends CallErrorsTestCase
{
    #[\Override]
    protected static function entry(): Entry
    {
        return Entry::BuilderAfterBuild;
    }
}
