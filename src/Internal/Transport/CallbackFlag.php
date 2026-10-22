<?php

declare(strict_types=1);

namespace AgentCat\Internal\Transport;

/**
 * Set while one of the PHP callbacks of `CurlHttpChannel` (its write and header functions) runs, inside
 * `curl_multi_exec()`. The callbacks hold this object, not the channel, so a finished handle keeps no channel alive.
 *
 * @internal
 */
final class CallbackFlag
{
    public bool $active = false;
}
