<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E;

/** StdioEndToEndTestCase with the fixture tracking the Builder before build(). */
final class StdioEndToEndTest extends StdioEndToEndTestCase
{
    #[\Override]
    protected static function tracked(): string
    {
        return 'builder';
    }
}
