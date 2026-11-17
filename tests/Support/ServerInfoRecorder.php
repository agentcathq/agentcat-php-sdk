<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\ServerInfo\Heartbeat;

/**
 * What stands in for the server info heartbeat in every test process (`tests/bootstrap.php`): it records each check
 * and does nothing else, so no test reads a list or sends a heartbeat unless it asks for the real one. A test of the
 * heartbeat calls `remove()` in `setUp()` and `install()` in `tearDown()`.
 */
final class ServerInfoRecorder
{
    /** @var list<array{project: string, always: bool}> the checks since the last `install()`, in order */
    public static array $checks = [];

    public static function install(): void
    {
        self::$checks = [];
        Heartbeat::replace(static function (TrackedServer $server, bool $always): void {
            self::$checks[] = ['project' => $server->projectId, 'always' => $always];
        });
    }

    /** Puts the real heartbeat back, with an empty memory. */
    public static function remove(): void
    {
        Heartbeat::replace(null);
        Heartbeat::forget();
    }
}
