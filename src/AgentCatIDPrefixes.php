<?php

declare(strict_types=1);

namespace AgentCat;

/**
 * ID prefixes for AgentCat identifiers. Mirrors the TypeScript `AgentCatIDPrefixes`.
 * `Agent` is reserved and not used yet.
 */
enum AgentCatIDPrefixes: string
{
    case Session = 'ses';
    case Event = 'evt';
    case Agent = 'agt';
}
