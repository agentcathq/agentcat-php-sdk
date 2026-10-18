<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Laravel;

use AgentCat\Identity;

/**
 * An `identify` hook of `config/agentcat.php` that takes its time: it sleeps `SECONDS`, writes the time it woke up to
 * `$HOME/<MARKER>`, and names the actor `e2e-user`. AgentCat runs hooks in the delivery, so the marker tells when the
 * delivery ran.
 */
final class SleepingIdentify
{
    public const int SECONDS = 3;

    public const string MARKER = 'identify-woke';

    public function __invoke(): Identity
    {
        sleep(self::SECONDS);
        file_put_contents(getenv('HOME') . '/' . self::MARKER, sprintf('%.6F', microtime(true)));

        return new Identity('e2e-user');
    }
}
