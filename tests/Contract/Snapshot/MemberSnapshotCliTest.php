<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\Snapshot;

use AgentCat\Tests\Support\FileTree;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** The exit codes of tests/bin/member-snapshot.php, which the nightly job relies on. */
/** @phpstan-import-type Snapshot from MemberSnapshot */
final class MemberSnapshotCliTest extends TestCase
{
    private string $directory = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = getenv('HOME') . '/snapshot-cli-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        FileTree::remove($this->directory);
    }

    public function testACleanDiffExitsZero(): void
    {
        $file = $this->snapshotFile(MemberSnapshot::of('mcp/sdk'));

        $process = $this->script(['mcp/sdk', '--diff', $file]);

        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString('#### breaking (0)', $process->getOutput());
    }

    public function testABreakingLineExitsOneAndNamesTheMember(): void
    {
        $tampered = $this->withTamperedType(MemberSnapshot::of('mcp/sdk'), \Mcp\Server::class, 'protocol');

        $process = $this->script(['mcp/sdk', '--diff', $this->snapshotFile($tampered)]);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('#### breaking (1)', $process->getOutput());
        $this->assertStringContainsString('Mcp\Server::$protocol: private readonly Tampered $protocol → private readonly Mcp\Server\Protocol $protocol', $process->getOutput());
    }

    public function testTheReportGoesToTheStepSummaryWhenItIsSet(): void
    {
        $summary = $this->directory . '/summary.md';

        $process = $this->script(['mcp/sdk', '--diff', $this->snapshotFile(MemberSnapshot::of('mcp/sdk'))], ['GITHUB_STEP_SUMMARY' => $summary]);

        $this->assertSame(0, $process->getExitCode());
        $this->assertSame($process->getOutput(), (string) file_get_contents($summary));
    }

    public function testAnUnknownLibraryExitsTwo(): void
    {
        $this->assertSame(2, $this->script(['symfony/mcp-bundle'])->getExitCode());
    }

    public function testAMissingSnapshotFileExitsTwo(): void
    {
        $this->assertSame(2, $this->script(['mcp/sdk', '--diff', $this->directory . '/missing.json'])->getExitCode());
    }

    public function testNoArgumentsExitTwo(): void
    {
        $this->assertSame(2, $this->script([])->getExitCode());
    }

    /** @param Snapshot $snapshot */
    private function snapshotFile(array $snapshot): string
    {
        $path = $this->directory . '/committed.json';
        file_put_contents($path, MemberSnapshot::toJson($snapshot));

        return $path;
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env the summary file is unset unless a test sets it, so a CI run never writes to its own
     */
    private function script(array $arguments, array $env = []): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/member-snapshot.php', ...$arguments], null, ['GITHUB_STEP_SUMMARY' => false, ...$env], null, 60);
        $process->run();

        return $process;
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
