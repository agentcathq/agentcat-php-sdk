<?php

declare(strict_types=1);

namespace AgentCat\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The jobs of the workflows that the required check and the nightly issue depend on. `All green` needs every CI job
 * that gates, and the `released` jobs of CI and the nightly test the releases that the `discover` job reads from
 * Packagist (D72), so a new release joins the matrix without a commit.
 */
final class WorkflowsTest extends TestCase
{
    private const string WORKFLOWS = __DIR__ . '/../../.github/workflows';

    private const string ISSUE_STEP = 'Open or update the nightly issue';

    public function testAllGreenNeedsEveryCiJobThatGates(): void
    {
        $jobs = $this->jobs('ci.yml');
        $gating = [];
        foreach ($jobs as $id => $job) {
            if ($id !== 'all-green' && ($job['continue-on-error'] ?? false) !== true) {
                $gating[] = $id;
            }
        }
        $needs = $jobs['all-green']['needs'] ?? null;
        $this->assertIsArray($needs);
        sort($gating);
        sort($needs);

        $this->assertSame($gating, $needs, 'All green needs every CI job but the experimental ones');
        $this->assertContains('released', $needs);
    }

    /** @return iterable<string, array{string}> */
    public static function workflows(): iterable
    {
        yield 'CI' => ['ci.yml'];
        yield 'the nightly' => ['nightly.yml'];
    }

    #[DataProvider('workflows')]
    public function testTheReleasedJobsTestTheDiscoveredReleases(string $workflow): void
    {
        $jobs = $this->jobs($workflow);

        $this->assertStringContainsString('php tests/bin/released-versions.php', $this->runs($jobs['discover'] ?? []));
        $this->assertSame('${{ steps.releases.outputs.releases }}', $this->at($jobs, 'discover', 'outputs', 'releases'));
        $this->assertSame('discover', $this->at($jobs, 'released', 'needs'));
        $this->assertSame('${{ fromJSON(needs.discover.outputs.releases) }}', $this->at($jobs, 'released', 'strategy', 'matrix', 'include'));
        $this->assertFalse($this->at($jobs, 'released', 'strategy', 'fail-fast'), 'one failing release does not cancel the others');
        $this->assertStringContainsString('tests/bin/require-library.sh "$LIBRARY" "$VERSION"', $this->runs($jobs['released'] ?? []));
        $this->assertStringContainsString('vendor/bin/phpunit --testsuite Contract,Integration,E2E', $this->runs($jobs['released'] ?? []));
    }

    public function testThePackageProbeRunsOnEachDiscoveredMcpSdkRelease(): void
    {
        $jobs = $this->jobs('ci.yml');

        $this->assertSame('discover', $this->at($jobs, 'package', 'needs'));
        $this->assertSame('${{ fromJSON(needs.discover.outputs.mcp-sdk) }}', $this->at($jobs, 'package', 'strategy', 'matrix', 'mcp-sdk'));
    }

    /** A scheduled failure reaches a person: every nightly job ends by opening or updating its issue. */
    public function testEveryNightlyJobOpensTheIssueWhenItFails(): void
    {
        foreach ($this->jobs('nightly.yml') as $id => $job) {
            $steps = $job['steps'] ?? [];
            $this->assertIsArray($steps);
            $last = end($steps);
            $this->assertIsArray($last, $id);
            $this->assertSame(self::ISSUE_STEP, $last['name'] ?? null, $id);
            $this->assertSame("failure() && github.event_name != 'pull_request'", $last['if'] ?? null, $id);
            $run = $last['run'] ?? null;
            $this->assertIsString($run, $id);
            $this->assertStringContainsString('.github/nightly-issue.sh', $run, $id);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function jobs(string $workflow): array
    {
        $parsed = Yaml::parseFile(self::WORKFLOWS . '/' . $workflow);
        $this->assertIsArray($parsed);
        $jobs = $parsed['jobs'] ?? null;
        $this->assertIsArray($jobs);

        /** @var array<string, array<string, mixed>> $jobs */
        return $jobs;
    }

    /**
     * The value at a path of keys, each level an array.
     *
     * @param array<string, mixed> $value
     */
    private function at(array $value, string ...$keys): mixed
    {
        $at = $value;
        foreach ($keys as $key) {
            $this->assertIsArray($at, implode('.', $keys));
            $at = $at[$key] ?? null;
        }

        return $at;
    }

    /**
     * The `run` scripts of a job's steps, one after the other.
     *
     * @param array<string, mixed> $job
     */
    private function runs(array $job): string
    {
        $steps = $job['steps'] ?? [];
        $this->assertIsArray($steps);
        $runs = '';
        foreach ($steps as $step) {
            if (is_array($step) && is_string($step['run'] ?? null)) {
                $runs .= $step['run'] . "\n";
            }
        }

        return $runs;
    }
}
