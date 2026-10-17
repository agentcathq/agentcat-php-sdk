<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

use AgentCat\Internal\SdkVersion;

/** Which mcp/sdk revision the suite runs against: the locked release, or main through tests/bin/mcp-sdk-main.sh. */
final class McpSdkVersion
{
    public static function isMain(): bool
    {
        return SdkVersion::installed('mcp/sdk') === 'dev-main';
    }
}
