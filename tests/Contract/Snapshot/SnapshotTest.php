<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\Snapshot;

use AgentCat\Tests\Contract\PrivateMemberManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The classes AgentCat touches, member by member, against the snapshot committed for the installed version. A
 * `dev-main` leg has no snapshot and skips; the nightly job diffs it with tests/bin/member-snapshot.php instead.
 */
final class SnapshotTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function libraries(): array
    {
        $rows = [];
        foreach (MemberSnapshot::libraries() as $library) {
            $rows[$library] = [$library];
        }

        return $rows;
    }

    #[DataProvider('libraries')]
    public function testTheInstalledVersionMatchesItsCommittedSnapshot(string $library): void
    {
        $version = MemberSnapshot::installedVersion($library);
        $path = __DIR__ . '/../snapshots/' . str_replace('/', '-', $library) . '-' . $version . '.json';
        if (!is_file($path)) {
            self::markTestSkipped('no committed snapshot for ' . $library . ' ' . $version . ' (a dev-main leg)');
        }

        $committed = MemberSnapshot::fromJson((string) file_get_contents($path));
        $live = MemberSnapshot::of($library);

        $diff = SnapshotDiff::between($committed, $live, PrivateMemberManifest::members());
        $this->assertSame([], $diff->breaking(), 'breaking changes in ' . $library . ' ' . $version);
        $this->assertSame([], $diff->other(), 'changes in ' . $library . ' ' . $version);
        $this->assertSame($committed, $live);
    }

    public function testTheSnapshotCoversEveryClassTheManifestNamesForTheLibrary(): void
    {
        $classes = array_keys(MemberSnapshot::of('mcp/sdk')['classes']);

        $this->assertContains(\Mcp\Server\Builder::class, $classes);
        $this->assertContains(\Mcp\Schema\Result\ListToolsResult::class, $classes);
        $this->assertNotContains(\Laravel\Mcp\Server::class, $classes);
        $this->assertContains(\Laravel\Mcp\Server::class, array_keys(MemberSnapshot::of('laravel/mcp')['classes']));
    }

    public function testSelfIsSpelledAsTheClassWhateverThePhpVersion(): void
    {
        $success = MemberSnapshot::of('mcp/sdk')['classes'][\Mcp\Schema\Result\CallToolResult::class]['methods']['success'] ?? null;

        $this->assertNotNull($success);
        $this->assertSame(\Mcp\Schema\Result\CallToolResult::class, $success['return']);
    }

    public function testAnUnknownLibraryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MemberSnapshot::of('symfony/mcp-bundle');
    }

    public function testASnapshotSurvivesItsJson(): void
    {
        $snapshot = MemberSnapshot::of('mcp/sdk');

        $this->assertSame($snapshot, MemberSnapshot::fromJson(MemberSnapshot::toJson($snapshot)));
    }
}
