<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E;

/** HttpEndToEndTestCase with the fixture tracking the server built from the Builder. */
final class HttpEndToEndOnBuiltServerTest extends HttpEndToEndTestCase
{
    #[\Override]
    protected static function tracked(): string
    {
        return 'server';
    }
}
