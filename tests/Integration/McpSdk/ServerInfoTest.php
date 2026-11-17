<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Tests\Support\McpSdk\Entry;

/** ServerInfoTestCase BeforeBuild. */
final class ServerInfoTest extends ServerInfoTestCase
{
    #[\Override]
    protected static function entry(): Entry
    {
        return Entry::BeforeBuild;
    }
}
