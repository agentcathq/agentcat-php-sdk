<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp\Internal;

use AgentCat\Internal\Support\Values;

/**
 * The client info that a legacy client sent in `initialize`, kept for one server. Over
 * stdio one server answers the whole process, so its later tool calls name that client; over HTTP every request builds
 * a new server, and the value is lost.
 *
 * @internal
 */
final class HandshakeClient
{
    private mixed $clientInfo = null;

    /** Keeps the value when it is an array or an object; anything else leaves the earlier client. */
    public function record(mixed $clientInfo): void
    {
        if (Values::isObjectLike($clientInfo)) {
            $this->clientInfo = $clientInfo;
        }
    }

    /** The client info of the last handshake that sent one; null before. */
    public function clientInfo(): mixed
    {
        return $this->clientInfo;
    }
}
