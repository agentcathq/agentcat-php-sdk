<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use Composer\Semver\VersionParser;

/**
 * The releases of the MCP libraries that the `released` and `package` jobs of ci.yml and nightly.yml test (D72): the
 * newest patch of each minor version, from the lowest version that `require-dev` allows upward. Like the TypeScript
 * SDK's `mcp-compatibility.yml`, `tests/bin/released-versions.php` reads them from Packagist on each run, so a new
 * release joins the matrix without a commit.
 */
final class ReleaseDiscovery
{
    /** The libraries that the adapters integrate, in the order of the matrix. */
    public const array LIBRARIES = ['mcp/sdk', 'laravel/mcp', 'symfony/mcp-bundle'];

    /** The PHP versions that CI tests, lowest first: the `php` matrix of the `tests` job in ci.yml. */
    public const array PHP_VERSIONS = ['8.3', '8.4', '8.5'];

    /** A `^` constraint: its base is the lowest version it allows. */
    private const string CARET = '/\A\^(\d+)\.(\d+)(?:\.(\d+))?\z/';

    /**
     * A stable release in Packagist's `version_normalized` form: four numbers, no stability suffix. A `-patchN` release,
     * which Composer also calls stable, does not count: none of the three libraries publishes one.
     */
    private const string STABLE = '/\A(\d+)\.(\d+)\.(\d+)\.(\d+)\z/';

    /** The value of a key that a minified Packagist row removes. */
    private const string UNSET = '__unset';

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
     * The rows of the matrix: for each library, the newest patch of each minor version at or above its floor, with the
     * lowest PHP version of PHP_VERSIONS that the release allows.
     *
     * @param array<string, string> $floors library => version
     * @param \Closure(string): string $fetch the body at a URL
     *
     * @return list<array{library: string, version: string, php: string}>
     *
     * @throws \RangeException when a library has no release at or above its floor
     * @throws \RuntimeException when `$fetch` fails or a body cannot be read
     */
    public static function matrix(array $floors, \Closure $fetch): array
    {
        $rows = [];
        foreach ($floors as $library => $floor) {
            $releases = self::stableReleases($fetch(self::metadataUrl($library)), $library);
            $versions = self::newestPatches(array_keys($releases), $floor);
            if ($versions === []) {
                throw new \RangeException(sprintf('Packagist has no release of %s at or above %s', $library, $floor));
            }
            foreach ($versions as $version) {
                $rows[] = ['library' => $library, 'version' => $version, 'php' => self::lowestPhp($releases[$version] ?? '*')];
            }
        }

        return $rows;
    }

    /**
     * The stable releases of a Packagist `p2` body, in its order: the version as major.minor.patch (a fourth number only
     * when it is not 0) => the release's PHP constraint, `*` when it sets none. Packagist minifies the body: a row holds
     * the keys that changed since the row before it, and `__unset` removes a key, so the rows expand in order.
     *
     * @return array<string, string>
     */
    public static function stableReleases(string $body, string $library): array
    {
        $metadata = json_decode($body, true);
        if (!is_array($metadata)) {
            throw new \UnexpectedValueException('the Packagist body of ' . $library . ' is not JSON');
        }
        $rows = is_array($metadata['packages'] ?? null) ? $metadata['packages'][$library] ?? null : null;
        if (!is_array($rows)) {
            throw new \UnexpectedValueException('the Packagist body has no versions of ' . $library);
        }
        $releases = [];
        $expanded = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \UnexpectedValueException('a version of ' . $library . ' on Packagist is not an object');
            }
            foreach ($row as $key => $value) {
                if ($value === self::UNSET) {
                    unset($expanded[$key]);
                } else {
                    $expanded[$key] = $value;
                }
            }
            $normalized = $expanded['version_normalized'] ?? null;
            if (!is_string($normalized)) {
                throw new \UnexpectedValueException('a version of ' . $library . ' on Packagist has no version_normalized');
            }
            if (preg_match(self::STABLE, $normalized, $parts) === 1) {
                $version = $parts[1] . '.' . $parts[2] . '.' . $parts[3] . ($parts[4] === '0' ? '' : '.' . $parts[4]);
                $php = is_array($expanded['require'] ?? null) ? $expanded['require']['php'] ?? null : null;
                $releases[$version] = is_string($php) ? $php : '*';
            }
        }

        return $releases;
    }

    /**
     * The lowest version of PHP_VERSIONS that a PHP constraint allows. When it allows none, the newest: Composer then
     * stops the install with the release's PHP requirement, which says that CI needs a newer PHP.
     */
    public static function lowestPhp(string $constraint): string
    {
        $parser = new VersionParser();
        $allowed = $parser->parseConstraints($constraint);
        foreach (self::PHP_VERSIONS as $php) {
            if ($allowed->matches($parser->parseConstraints($php . '.*'))) {
                return $php;
            }
        }

        return self::PHP_VERSIONS[count(self::PHP_VERSIONS) - 1];
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
