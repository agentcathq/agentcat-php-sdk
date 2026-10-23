<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E;

/** HttpEndToEndTestCase with the fixture tracking the Builder before build(). */
final class HttpEndToEndTest extends HttpEndToEndTestCase
{
    #[\Override]
    protected static function tracked(): string
    {
        return 'builder';
    }
}
