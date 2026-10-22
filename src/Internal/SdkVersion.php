<?php

declare(strict_types=1);

namespace AgentCat\Internal;

use Composer\InstalledVersions;

/** @internal */
final class SdkVersion
{
    public static function get(): string
    {
        return self::resolve(
            static fn(): ?string => InstalledVersions::getPrettyVersion('agentcat/sdk'),
        );
    }

    /**
     * Turns a version lookup into a non-empty string. Never throws.
     *
     * @param \Closure(): ?string $lookup
     */
    public static function resolve(\Closure $lookup): string
    {
        try {
            $pretty = $lookup();
        } catch (\Throwable) {
            return 'dev';
        }

        return ($pretty === null || $pretty === '') ? 'dev' : $pretty;
    }
}
