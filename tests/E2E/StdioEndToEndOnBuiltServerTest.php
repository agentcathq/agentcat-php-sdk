<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E;

/** StdioEndToEndTestCase with the fixture tracking the server built from the Builder. */
final class StdioEndToEndOnBuiltServerTest extends StdioEndToEndTestCase
{
    #[\Override]
    protected static function tracked(): string
    {
        return 'server';
    }
}
