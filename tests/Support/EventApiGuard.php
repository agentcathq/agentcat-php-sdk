<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Tests\Integration\Support\MockCollector;

/**
 * The event API of every test process (`tests/bootstrap.php`): `MCPCAT_API_URL` at a closed port on 127.0.0.1, so an
 * event that no test points at a collector of its own fails there and never reaches the AgentCat API. It is the last
 * name `ApiBaseUrl` reads, so a test's own option, `AGENTCAT_API_URL` or `MCPCAT_API_URL` comes first. A test that
 * clears `MCPCAT_API_URL` with `Environment::clear()` sets the guard again after it; a test of the default URL removes
 * the guard, and its `Environment` puts it back after the test.
 */
final class EventApiGuard
{
    public const string NAME = 'MCPCAT_API_URL';

    private static ?string $url = null;

    /** The guard's URL: one closed port for the whole process. */
    public static function url(): string
    {
        return self::$url ??= MockCollector::closedPortUrl();
    }

    /** Sets the guard in the three places that `Env::get()` reads; a child process inherits it. */
    public static function set(): void
    {
        putenv(self::NAME . '=' . self::url());
        $_ENV[self::NAME] = $_SERVER[self::NAME] = self::url();
    }

    /** Removes the guard, for a test of the default URL; the test's `Environment` puts it back in `tearDown()`. */
    public static function remove(): void
    {
        putenv(self::NAME);
        unset($_ENV[self::NAME], $_SERVER[self::NAME]);
    }
}
