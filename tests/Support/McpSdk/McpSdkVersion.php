<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

use AgentCat\Internal\SdkVersion;

/** Which mcp/sdk revision the suite runs against: the locked release, or another one through tests/bin/mcp-sdk-leg.sh. */
final class McpSdkVersion
{
    /** Whether the installed mcp/sdk serves the 2026-07-28 era over stdio: 0.9.0 and later, or dev-main. Composer's pretty version of a tag can carry the `v` (v0.9.0), which version_compare() ranks below 0.9.0. */
    public static function hasModernEra(): bool
    {
        $installed = SdkVersion::installed('mcp/sdk');

        return $installed === 'dev-main' || ($installed !== null && version_compare(ltrim($installed, 'v'), '0.9.0', '>='));
    }
}
