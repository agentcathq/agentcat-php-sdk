<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\Snapshot;

use AgentCat\Tests\Contract\PrivateMemberManifest;
use AgentCat\Tests\Support\FileTree;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The classes AgentCat touches, member by member, against the snapshot committed for the installed version. A
 * `dev-main` leg has no snapshot and skips; the nightly job diffs it with tests/bin/member-snapshot.php instead.
 */
/** @phpstan-import-type Snapshot from MemberSnapshot */
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
        $files = CommittedSnapshots::committed();
        if ($files->exact($library, $version) === null && str_starts_with($version, 'dev-')) {
            self::markTestSkipped('no committed snapshot for ' . $library . ' ' . $version . ' (a dev-main leg)');
        }
        // A release that has no snapshot yet (a new patch) is compared with the newest lower one: only a change to a
        // member AgentCat reads fails, so an upstream patch does not break the build until someone commits its snapshot.
        $result = $files->compare($library, $version, MemberSnapshot::of($library), PrivateMemberManifest::members());
        $this->assertNotNull($result, 'no committed snapshot of ' . $library . ' at or below ' . $version);

        $report = $library . ' ' . $version . ' against ' . basename($result['path']) . ":\n" . implode("\n", $result['diff']->other());
        $this->assertSame([], $result['diff']->breaking(), 'breaking changes in ' . $report);
        if ($result['exact']) {
            $this->assertSame([], $result['diff']->other(), 'changes in ' . $report);
        }
    }

    public function testAVersionWithoutASnapshotIsComparedWithTheNewestLowerOne(): void
    {
        $directory = getenv('HOME') . '/snapshots-' . bin2hex(random_bytes(4));
        mkdir($directory);

        try {
            foreach (['0.9.0', '0.10.0', '0.8.1', '0.11.0-rc1'] as $version) {
                file_put_contents($directory . '/mcp-sdk-' . $version . '.json', '{}');
            }
            $files = new CommittedSnapshots($directory);

            $this->assertSame($directory . '/mcp-sdk-0.9.0.json', $files->newestBelow('mcp/sdk', '0.9.5'));
            $this->assertSame($directory . '/mcp-sdk-0.10.0.json', $files->newestBelow('mcp/sdk', '0.10.1'));
            $this->assertNull($files->newestBelow('mcp/sdk', '0.8.0'));
            $this->assertNull($files->newestBelow('laravel/mcp', '9.9.9'));
            $this->assertSame($directory . '/mcp-sdk-0.9.0.json', $files->exact('mcp/sdk', '0.9.0'));
            $this->assertNull($files->exact('mcp/sdk', '0.9.1'));
            $this->assertCount(4, $files->all('mcp/sdk'));
        } finally {
            FileTree::remove($directory);
        }
    }

    #[DataProvider('libraries')]
    public function testAFakeNewPatchPassesWhenNothingAgentCatReadsChanged(string $library): void
    {
        $live = MemberSnapshot::of($library);
        $directory = $this->directoryWith($library, '0.0.1', $live);

        try {
            $result = (new CommittedSnapshots($directory))->compare($library, '0.0.2', $live, PrivateMemberManifest::members());
        } finally {
            FileTree::remove($directory);
        }

        $this->assertNotNull($result);
        $this->assertFalse($result['exact']);
        $this->assertSame([], $result['diff']->breaking());
        $this->assertSame([], $result['diff']->other());
    }

    #[DataProvider('libraries')]
    public function testAFakeNewPatchFailsWhenAMemberAgentCatReadsChanged(string $library): void
    {
        $live = MemberSnapshot::of($library);
        $tampered = $live;
        $owner = null;
        foreach (SnapshotDiff::readMembers(PrivateMemberManifest::members())['properties'] as $class => $names) {
            if (!str_starts_with($class, MemberSnapshot::namespaceOf($library))) {
                continue;
            }
            foreach ($names as $name) {
                $owner = $class . '::$' . $name;
                $tampered = $this->withTamperedType($live, $class, $name);

                break 2;
            }
        }
        $directory = $this->directoryWith($library, '0.0.1', $tampered);

        try {
            $result = (new CommittedSnapshots($directory))->compare($library, '0.0.2', $live, PrivateMemberManifest::members());
        } finally {
            FileTree::remove($directory);
        }

        $this->assertNotNull($owner);
        $this->assertNotNull($result);
        $breaking = $result['diff']->breaking();
        $this->assertCount(1, $breaking);
        $this->assertStringStartsWith($owner . ': ', implode('', $breaking));
        $this->assertStringContainsString('Tampered', implode('', $breaking));
    }

    public function testAFakeVersionBelowEverySnapshotHasNothingToCompareWith(): void
    {
        $live = MemberSnapshot::of('mcp/sdk');
        $directory = $this->directoryWith('mcp/sdk', '0.5.0', $live);

        try {
            $result = (new CommittedSnapshots($directory))->compare('mcp/sdk', '0.4.0', $live, PrivateMemberManifest::members());
        } finally {
            FileTree::remove($directory);
        }

        $this->assertNull($result);
    }

    public function testEveryMemberTheManifestListsIsInEveryCommittedSnapshotOfItsLibrary(): void
    {
        $read = SnapshotDiff::readMembers(PrivateMemberManifest::members());
        $checked = 0;
        foreach (MemberSnapshot::libraries() as $library) {
            foreach (CommittedSnapshots::committed()->all($library) as $path) {
                $classes = MemberSnapshot::fromJson((string) file_get_contents($path))['classes'];
                foreach ($read['properties'] as $class => $names) {
                    if (str_starts_with($class, MemberSnapshot::namespaceOf($library))) {
                        foreach ($names as $name) {
                            $this->assertArrayHasKey($name, $classes[$class]['properties'] ?? [], basename($path) . ': ' . $class . '::$' . $name);
                            ++$checked;
                        }
                    }
                }
                foreach ($read['methods'] as $class => $names) {
                    if (str_starts_with($class, MemberSnapshot::namespaceOf($library))) {
                        foreach ($names as $name) {
                            $this->assertArrayHasKey($name, $classes[$class]['methods'] ?? [], basename($path) . ': ' . $class . '::' . $name . '()');
                            ++$checked;
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(30, $checked);
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
        $e = Thrown::by(\InvalidArgumentException::class, static fn(): array => MemberSnapshot::of('symfony/mcp-bundle'));

        $this->assertSame('unknown library symfony/mcp-bundle (expected laravel/mcp or mcp/sdk)', $e->getMessage());
    }

    public function testASnapshotSurvivesItsJson(): void
    {
        $snapshot = MemberSnapshot::of('mcp/sdk');

        $this->assertSame($snapshot, MemberSnapshot::fromJson(MemberSnapshot::toJson($snapshot)));
    }

    /** @param Snapshot $snapshot */
    private function directoryWith(string $library, string $version, array $snapshot): string
    {
        $directory = getenv('HOME') . '/snapshots-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/' . str_replace('/', '-', $library) . '-' . $version . '.json', MemberSnapshot::toJson($snapshot));

        return $directory;
    }

    /**
     * The snapshot with the type of one property changed.
     *
     * @param Snapshot $snapshot
     *
     * @return Snapshot
     */
    private function withTamperedType(array $snapshot, string $class, string $name): array
    {
        $changed = array_replace_recursive($snapshot, ['classes' => [$class => ['properties' => [$name => ['type' => 'Tampered']]]]]);

        return MemberSnapshot::fromJson(json_encode($changed, JSON_THROW_ON_ERROR));
    }
}
