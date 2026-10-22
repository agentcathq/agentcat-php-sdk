<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

use AgentCat\Internal\SdkVersion;

/** Which mcp/sdk revision the suite runs against: the locked release, or another one through tests/bin/mcp-sdk-leg.sh. */
final class McpSdkVersion
{
    /** Whether the installed mcp/sdk serves the 2026-07-28 era over stdio: 0.9.0 and later, or dev-main. */
    public static function hasModernEra(): bool
    {
        $installed = SdkVersion::installed('mcp/sdk');

        return $installed === 'dev-main' || ($installed !== null && version_compare($installed, '0.9.0', '>='));
    }
}
