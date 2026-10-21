<?php

declare(strict_types=1);

namespace AgentCat\Internal;

use Composer\InstalledVersions;

/**
 * The versions that AgentCat reports: its own and those of the MCP libraries, as Composer installed them, without the
 * `v` of a tag name (`v0.9.0` is `0.9.0`, as the TypeScript SDK and the backend name versions).
 *
 * @internal
 */
final class SdkVersion
{
    public static function get(): string
    {
        return self::resolve(
            static fn(): ?string => InstalledVersions::getPrettyVersion('agentcat/sdk'),
        );
    }

    /** The Composer pretty version of a package without the `v` of a tag, or null when it is not installed. Never throws. */
    public static function installed(string $package): ?string
    {
        try {
            $pretty = InstalledVersions::getPrettyVersion($package);
        } catch (\Throwable) {
            return null;
        }

        return $pretty === null ? null : self::bare($pretty);
    }

    /**
     * Turns a version lookup into a non-empty string without the `v` of a tag. Never throws.
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

        $version = $pretty === null ? '' : self::bare($pretty);

        return $version === '' ? 'dev' : $version;
    }

    /** A pretty version without the leading `v` of a tag name; a branch (`dev-main`, `1.x-dev`) has none. */
    private static function bare(string $pretty): string
    {
        return str_starts_with($pretty, 'v') ? substr($pretty, 1) : $pretty;
    }
}
