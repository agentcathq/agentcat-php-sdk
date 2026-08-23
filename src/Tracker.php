<?php

declare(strict_types=1);

namespace AgentCat;

/**
 * AgentCat - analytics for MCP (Model Context Protocol) servers.
 *
 * This release is a name placeholder. The official AgentCat PHP SDK, built on
 * the official Model Context Protocol PHP SDK (the mcp/sdk package), is in
 * active development and will ship as version 1.0.0.
 *
 * Until then, see the reference implementations:
 *   TypeScript - https://github.com/agentcathq/agentcat-typescript-sdk
 *   Python     - https://github.com/agentcathq/agentcat-python-sdk
 *   Go         - https://github.com/agentcathq/agentcat-go-sdk
 */
final class Tracker
{
    public const VERSION = '0.0.1';

    /**
     * Placeholder for the SDK entry point.
     *
     * @throws NotYetImplementedException always, in this release.
     */
    public static function track(): void
    {
        throw new NotYetImplementedException(
            'The AgentCat PHP SDK is not released yet. This package reserves the '
            . 'name for the official implementation. See https://agentcat.com for status.'
        );
    }
}
