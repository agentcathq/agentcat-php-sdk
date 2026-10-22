<?php

declare(strict_types=1);

namespace AgentCat\Internal\Handles;

/**
 * Where a session id came from.
 *
 * @internal
 */
enum SessionIdSource: string
{
    case Minted = 'minted';
    case Supplied = 'supplied';
    case Invalid = 'invalid';
    case Foreign = 'foreign';
    case Hook = 'hook';
}
