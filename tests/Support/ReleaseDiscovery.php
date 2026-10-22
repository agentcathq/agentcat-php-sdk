<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

/**
 * The releases of the MCP libraries that the `released` jobs of ci.yml and nightly.yml test (D72): the newest patch of
 * each minor version, from the lowest version that `require-dev` allows upward. Like the TypeScript SDK's
 * `mcp-compatibility.yml`, `tests/bin/released-versions.php` reads them from Packagist on each run, so a new release
 * joins the matrix without a commit.
 */
final class ReleaseDiscovery
{
    /** The libraries that the adapters integrate, in the order of the matrix. */
    public const array LIBRARIES = ['mcp/sdk', 'laravel/mcp', 'symfony/mcp-bundle'];

    /** A `^` constraint: its base is the lowest version it allows. */
    private const string CARET = '/\A\^(\d+)\.(\d+)(?:\.(\d+))?\z/';

    /** A stable release in Packagist's `version_normalized` form: four numbers, no stability suffix. */
    private const string STABLE = '/\A(\d+)\.(\d+)\.(\d+)\.(\d+)\z/';

    /**
     * The floor of each library: the lowest version of its `require-dev` constraint, which the lowest-dependencies jobs
     * test.
     *
     * @return array<string, string> library => version, in the order of LIBRARIES
     */
    public static function floors(string $composerJson): array
    {
        $composer = json_decode($composerJson, true, flags: \JSON_THROW_ON_ERROR);
        $requireDev = is_array($composer) && is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [];
        $floors = [];
        foreach (self::LIBRARIES as $library) {
            $constraint = $requireDev[$library] ?? null;
            if (!is_string($constraint)) {
                throw new \UnexpectedValueException('composer.json has no require-dev constraint for ' . $library);
            }
            $floors[$library] = self::floor($constraint);
        }

        return $floors;
    }

    /** The lowest version that `^` constraints joined by `||` allow, as major.minor.patch. */
    public static function floor(string $constraint): string
    {
        $alternatives = preg_split('/\s*\|\|?\s*/', trim($constraint));
        $floor = null;
        foreach (is_array($alternatives) ? $alternatives : [] as $alternative) {
            if (preg_match(self::CARET, $alternative, $parts) !== 1) {
                throw self::unreadable($constraint);
            }
            $version = $parts[1] . '.' . $parts[2] . '.' . ($parts[3] ?? '0');
            if ($floor === null || version_compare($version, $floor, '<')) {
                $floor = $version;
            }
        }

        return $floor ?? throw self::unreadable($constraint);
    }

    /** The Packagist metadata of a library's tags; its branches are in `<name>~dev.json`. */
    public static function metadataUrl(string $library): string
    {
        return 'https://repo.packagist.org/p2/' . $library . '.json';
    }

    /**
     * The stable versions of a Packagist `p2` body, in its order, as major.minor.patch (a fourth number only when it is
     * not 0). Packagist minifies the body, but each row keeps its own `version_normalized`.
     *
     * @return list<string>
     */
    public static function stableVersions(string $body, string $library): array
    {
        $metadata = json_decode($body, true);
        if (!is_array($metadata)) {
            throw new \UnexpectedValueException('the Packagist body of ' . $library . ' is not JSON');
        }
        $rows = is_array($metadata['packages'] ?? null) ? $metadata['packages'][$library] ?? null : null;
        if (!is_array($rows)) {
            throw new \UnexpectedValueException('the Packagist body has no versions of ' . $library);
        }
        $versions = [];
        foreach ($rows as $row) {
            $normalized = is_array($row) ? $row['version_normalized'] ?? null : null;
            if (!is_string($normalized)) {
                throw new \UnexpectedValueException('a version of ' . $library . ' on Packagist has no version_normalized');
            }
            if (preg_match(self::STABLE, $normalized, $parts) === 1) {
                $versions[] = $parts[1] . '.' . $parts[2] . '.' . $parts[3] . ($parts[4] === '0' ? '' : '.' . $parts[4]);
            }
        }

        return $versions;
    }

    /**
     * The newest patch of each minor version at or above the floor, oldest first.
     *
     * @param list<string> $versions
     *
     * @return list<string>
     */
    public static function newestPatches(array $versions, string $floor): array
    {
        $newest = [];
        foreach ($versions as $version) {
            if (version_compare($version, $floor, '<')) {
                continue;
            }
            $minor = implode('.', array_slice(explode('.', $version), 0, 2));
            if (!isset($newest[$minor]) || version_compare($version, $newest[$minor], '>')) {
                $newest[$minor] = $version;
            }
        }
        $patches = array_values($newest);
        // Each part is digits only, so the natural order is the version order: 0.9.0 before 0.10.0.
        sort($patches, \SORT_NATURAL);

        return $patches;
    }

    private static function unreadable(string $constraint): \InvalidArgumentException
    {
        return new \InvalidArgumentException('release discovery reads only ^ constraints joined by ||, got "' . $constraint . '"');
    }
}
