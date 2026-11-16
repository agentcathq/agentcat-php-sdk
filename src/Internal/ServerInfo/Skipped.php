<?php

declare(strict_types=1);

namespace AgentCat\Internal\ServerInfo;

/**
 * Why a heartbeat is not sent; the message ends its log line.
 *
 * @internal
 */
final class Skipped extends \RuntimeException
{
    /** The heartbeat is not sent because of `$cause`; `$reason` says what failed. */
    public static function after(string $reason, \Throwable $cause): self
    {
        return new self($reason, 0, $cause);
    }
}
