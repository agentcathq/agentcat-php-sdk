<?php

declare(strict_types=1);

namespace AgentCat\Internal;

use Composer\InstalledVersions;

/** @internal */
final class SdkVersion
{
    private static ?string $version = null;

    public static function get(): string
    {
        if (self::$version !== null) {
            return self::$version;
        }

        try {
            $pretty = InstalledVersions::getPrettyVersion('agentcat/sdk');
        } catch (\Throwable) {
            $pretty = null;
        }

        return self::$version = ($pretty === null || $pretty === '') ? 'dev' : $pretty;
    }
}
