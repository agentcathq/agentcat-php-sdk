<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit;

use AgentCat\Tests\Support\ReleaseDiscovery;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The selection of the MCP library releases that the `released` jobs of CI and the nightly test (D72): the newest patch
 * of each minor version, from the lowest version that `require-dev` allows, read from Packagist.
 */
final class ReleaseDiscoveryTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    /** @return iterable<string, array{string, string}> */
    public static function constraints(): iterable
    {
        yield 'a caret with a patch' => ['^0.8.1', '0.8.1'];
        yield 'a caret without a patch' => ['^1.0', '1.0.0'];
        yield 'two carets' => ['^0.13 || ^0.14', '0.13.0'];
        yield 'two carets, the newer first' => ['^0.14 || ^0.13', '0.13.0'];
        yield 'one pipe' => ['^12.41.1 | ^13.0', '12.41.1'];
        yield 'a two-digit minor' => ['^0.10 || ^0.9.2', '0.9.2'];
    }

    #[DataProvider('constraints')]
    public function testTheFloorIsTheLowestVersionTheConstraintAllows(string $constraint, string $floor): void
    {
        $this->assertSame($floor, ReleaseDiscovery::floor($constraint));
    }

    /** @return iterable<string, array{string}> */
    public static function otherConstraints(): iterable
    {
        yield 'a range' => ['>=1.0'];
        yield 'a tilde' => ['~0.0.4'];
        yield 'a wildcard' => ['1.*'];
        yield 'a caret and a range' => ['^1.0 || >=2.0'];
        yield 'nothing' => [''];
    }

    /** CI must test the floor that Composer lets a customer install, so a constraint it cannot read stops the run. */
    #[DataProvider('otherConstraints')]
    public function testAConstraintOtherThanCaretsIsRejected(string $constraint): void
    {
        $thrown = Thrown::by(\InvalidArgumentException::class, static fn(): string => ReleaseDiscovery::floor($constraint));

        $this->assertSame('release discovery reads only ^ constraints joined by ||, got "' . $constraint . '"', $thrown->getMessage());
    }

    public function testTheFloorsComeFromRequireDev(): void
    {
        $composer = $this->composer([
            'require-dev' => ['mcp/sdk' => '^0.8.1', 'laravel/mcp' => '^1.0', 'symfony/mcp-bundle' => '^0.13 || ^0.14', 'phpunit/phpunit' => '^12.5'],
        ]);

        $this->assertSame(['mcp/sdk' => '0.8.1', 'laravel/mcp' => '1.0.0', 'symfony/mcp-bundle' => '0.13.0'], ReleaseDiscovery::floors($composer));
    }

    public function testALibraryMissingFromRequireDevStopsTheRun(): void
    {
        $composer = $this->composer(['require-dev' => ['mcp/sdk' => '^0.8.1', 'laravel/mcp' => '^1.0']]);

        $thrown = Thrown::by(\UnexpectedValueException::class, static fn(): array => ReleaseDiscovery::floors($composer));

        $this->assertSame('composer.json has no require-dev constraint for symfony/mcp-bundle', $thrown->getMessage());
    }

    /**
     * A customer can install any version at or above the `conflict` floor, so the lowest-dependencies jobs and the
     * discovery must start there too.
     */
    public function testTheRepositoryFloorsMatchItsConflictFloors(): void
    {
        $json = (string) file_get_contents(self::ROOT . '/composer.json');
        $floors = ReleaseDiscovery::floors($json);
        /** @var array{conflict: array<string, string>} $composer */
        $composer = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        $conflictFloors = [];
        foreach (array_intersect_key($composer['conflict'], $floors) as $library => $constraint) {
            $this->assertMatchesRegularExpression('/\A<\d+\.\d+(?:\.\d+)?\z/', $constraint);
            $conflictFloors[$library] = ReleaseDiscovery::floor('^' . substr($constraint, 1));
        }
        ksort($conflictFloors);
        $expected = array_intersect_key($floors, $conflictFloors);
        ksort($expected);

        $this->assertSame(ReleaseDiscovery::LIBRARIES, array_keys($floors));
        $this->assertArrayHasKey('mcp/sdk', $conflictFloors);
        $this->assertArrayHasKey('laravel/mcp', $conflictFloors);
        $this->assertSame($expected, $conflictFloors);
    }

    /** Packagist minifies the rows: a row inherits each key it leaves out, and `__unset` removes one. */
    public function testTheStableReleasesOfAMinifiedPackagistBody(): void
    {
        $body = $this->packagist('mcp/sdk', [
            ['version' => 'v0.9.0', 'version_normalized' => '0.9.0.0', 'require' => ['php' => '>=8.4']],
            ['version' => 'v0.9.0-RC1', 'version_normalized' => '0.9.0.0-RC1', 'require' => ['php' => '^8.1']],
            ['version' => 'v0.8.1', 'version_normalized' => '0.8.1.0'],
            ['version' => '0.8.0-beta.2', 'version_normalized' => '0.8.0.0-beta2'],
            ['version' => 'dev-main', 'version_normalized' => 'dev-main'],
            ['version' => '1.2.3.4', 'version_normalized' => '1.2.3.4', 'require' => '__unset'],
        ]);

        $this->assertSame(['0.9.0' => '>=8.4', '0.8.1' => '^8.1', '1.2.3.4' => '*'], ReleaseDiscovery::stableReleases($body, 'mcp/sdk'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function unreadableBodies(): iterable
    {
        yield 'not JSON' => ['<html>', 'the Packagist body of mcp/sdk is not JSON'];
        yield 'another package' => ['{"packages":{"laravel/mcp":[]}}', 'the Packagist body has no versions of mcp/sdk'];
        yield 'no packages' => ['{"minified":"composer/2.0"}', 'the Packagist body has no versions of mcp/sdk'];
        yield 'a row without its version' => ['{"packages":{"mcp/sdk":[{"version":"v0.9.0"}]}}', 'a version of mcp/sdk on Packagist has no version_normalized'];
        yield 'a row that is no object' => ['{"packages":{"mcp/sdk":["0.9.0"]}}', 'a version of mcp/sdk on Packagist is not an object'];
    }

    #[DataProvider('unreadableBodies')]
    public function testABodyItCannotReadStopsTheRun(string $body, string $message): void
    {
        $thrown = Thrown::by(\UnexpectedValueException::class, static fn(): array => ReleaseDiscovery::stableReleases($body, 'mcp/sdk'));

        $this->assertSame($message, $thrown->getMessage());
    }

    /** @return iterable<string, array{string, string}> */
    public static function phpConstraints(): iterable
    {
        yield 'every version' => ['^8.1', '8.3'];
        yield 'no requirement' => ['*', '8.3'];
        yield 'a newer floor' => ['>=8.4', '8.4'];
        yield 'two carets' => ['^8.2 || ^8.3', '8.3'];
        yield 'a patch floor' => ['^8.4.2', '8.4'];
        yield 'one minor' => ['~8.5.0', '8.5'];
        yield 'a version CI does not test' => ['^8.6', '8.5'];
    }

    /**
     * The package probe runs each release on the lowest PHP that CI tests and the release allows, so a release that
     * drops PHP 8.3 still runs. A release that allows none runs on the newest, where Composer names its requirement.
     */
    #[DataProvider('phpConstraints')]
    public function testTheLowestPhpVersionThatTheReleaseAllows(string $constraint, string $php): void
    {
        $this->assertSame($php, ReleaseDiscovery::lowestPhp($constraint));
    }

    public function testTheMatrixHoldsTheNewestPatchOfEachMinorOfEachLibrary(): void
    {
        $bodies = [
            'https://repo.packagist.org/p2/mcp/sdk.json' => $this->packagist('mcp/sdk', [
                ['version_normalized' => '0.10.0.0', 'require' => ['php' => '>=8.4']],
                ['version_normalized' => '0.9.1.0', 'require' => ['php' => '^8.1']],
                ['version_normalized' => '0.9.0.0'],
                ['version_normalized' => '0.8.1.0'],
            ]),
            'https://repo.packagist.org/p2/laravel/mcp.json' => $this->packagist('laravel/mcp', [
                ['version_normalized' => '1.0.0.0', 'require' => ['php' => '^8.2']],
            ]),
        ];
        $fetched = [];
        $fetch = static function (string $url) use ($bodies, &$fetched): string {
            $fetched[] = $url;

            return $bodies[$url] ?? throw new \RuntimeException('GET ' . $url . ': HTTP 404');
        };

        $rows = ReleaseDiscovery::matrix(['mcp/sdk' => '0.9.0', 'laravel/mcp' => '1.0.0'], $fetch);

        $this->assertSame([
            ['library' => 'mcp/sdk', 'version' => '0.9.1', 'php' => '8.3'],
            ['library' => 'mcp/sdk', 'version' => '0.10.0', 'php' => '8.4'],
            ['library' => 'laravel/mcp', 'version' => '1.0.0', 'php' => '8.3'],
        ], $rows);
        $this->assertSame(array_keys($bodies), $fetched);
    }

    public function testALibraryWithoutAReleaseAtItsFloorStopsTheRun(): void
    {
        $body = $this->packagist('mcp/sdk', [['version_normalized' => '0.7.1.0']]);

        $thrown = Thrown::by(\RangeException::class, static fn(): array => ReleaseDiscovery::matrix(['mcp/sdk' => '0.8.1'], static fn(): string => $body));

        $this->assertSame('Packagist has no release of mcp/sdk at or above 0.8.1', $thrown->getMessage());
    }

    /** @return iterable<string, array{list<string>, string, list<string>}> */
    public static function releases(): iterable
    {
        yield 'mcp/sdk today' => [['0.9.0', '0.8.1', '0.8.0', '0.7.1', '0.7.0'], '0.8.1', ['0.8.1', '0.9.0']];
        yield 'laravel/mcp today' => [['1.1.1', '1.1.0', '1.0.2', '1.0.1', '1.0.0', '0.9.7'], '1.0.0', ['1.0.2', '1.1.1']];
        yield 'a new patch' => [['0.9.1', '0.9.0', '0.8.1'], '0.8.1', ['0.8.1', '0.9.1']];
        yield 'a new minor' => [['0.10.0', '0.9.0', '0.8.1'], '0.8.1', ['0.8.1', '0.9.0', '0.10.0']];
        yield 'a new major' => [['2.0.0', '1.1.1', '1.0.0'], '1.0.0', ['1.0.0', '1.1.1', '2.0.0']];
        yield 'a floor inside a minor' => [['0.8.2', '0.8.1', '0.8.0'], '0.8.1', ['0.8.2']];
        yield 'a patch below the floor' => [['0.8.0', '0.9.0'], '0.8.1', ['0.9.0']];
        yield 'numbers, not text' => [['10.0.0', '9.10.0', '9.9.0', '2.0.0'], '1.0.0', ['2.0.0', '9.9.0', '9.10.0', '10.0.0']];
        yield 'nothing at the floor' => [['0.7.1'], '0.8.1', []];
    }

    /**
     * @param list<string> $versions
     * @param list<string> $expected
     */
    #[DataProvider('releases')]
    public function testTheNewestPatchOfEachMinorAtOrAboveTheFloor(array $versions, string $floor, array $expected): void
    {
        $this->assertSame($expected, ReleaseDiscovery::newestPatches($versions, $floor));
    }

    public function testTheMetadataUrlHoldsTheTaggedReleases(): void
    {
        $this->assertSame('https://repo.packagist.org/p2/symfony/mcp-bundle.json', ReleaseDiscovery::metadataUrl('symfony/mcp-bundle'));
    }

    /** @param array<string, mixed> $composer */
    private function composer(array $composer): string
    {
        return json_encode($composer, \JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string, mixed>> $versions */
    private function packagist(string $library, array $versions): string
    {
        return json_encode(['minified' => 'composer/2.0', 'packages' => [$library => $versions]], \JSON_THROW_ON_ERROR);
    }
}
