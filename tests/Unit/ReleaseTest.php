<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit;

use AgentCat\Tests\Support\FileTree;
use AgentCat\Tests\Support\Release;
use AgentCat\Tests\Support\Thrown;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** The steps of .github/workflows/release.yml (tests/bin/release.php), as in the Java and Rust SDKs. */
final class ReleaseTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    private const string REPO = Release::REPOSITORY;

    private const string CHANGELOG = <<<'MD'
        # Changelog

        Intro.

        ## [Unreleased]

        ### Added

        - A feature.

        ## [1.0.0] - 2026-10-22

        ### Added

        - The first release.

        [Unreleased]: https://github.com/agentcathq/agentcat-php-sdk/compare/v1.0.0...HEAD
        [1.0.0]: https://github.com/agentcathq/agentcat-php-sdk/releases/tag/v1.0.0

        MD;

    /** @var list<string> the git repositories that a test made; tearDown() removes them */
    private array $dirs = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            FileTree::remove($dir);
        }
        $this->dirs = [];
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function notVersions(): iterable
    {
        foreach (['', '1', '1.2', '1.2.3.4', '01.2.3', '1.2.x', 'v1.2.3', '1.2.3-rc.1', '1..3', "1.2.3\n", ' 1.2.3'] as $text) {
            yield var_export($text, true) => [$text];
        }
    }

    public function testVersionsParseAsMajorMinorPatch(): void
    {
        $this->assertSame([1, 20, 3], Release::parse('1.20.3'));
        $this->assertSame([0, 0, 0], Release::parse('0.0.0'));
        $this->assertSame('1.20.3', Release::format([1, 20, 3]));
    }

    #[DataProvider('notVersions')]
    public function testOnlyMajorMinorPatchIsAVersion(string $text): void
    {
        $this->assertNull(Release::parse($text));
    }

    public function testTheNewestTagComparesNumbersAndIgnoresTagsThatAreNotVersions(): void
    {
        $this->assertSame([1, 10, 0], Release::newestTag(['v1.0.0', 'v1.10.0', 'v1.9.9', 'attempt-1', 'v2.0', '1.99.0', 'v3.0.0-rc.1']));
        $this->assertNull(Release::newestTag(['attempt-1', 'v2.0.0-beta']));
        $this->assertNull(Release::newestTag([]));
    }

    public function testWordsCountFromTheNewestTag(): void
    {
        $newest = [1, 2, 3];

        $this->assertSame([1, 2, 4], Release::nextVersion('patch', $newest));
        $this->assertSame([1, 3, 0], Release::nextVersion('minor', $newest));
        $this->assertSame([2, 0, 0], Release::nextVersion('major', $newest));
        $this->assertSame([1, 5, 0], Release::nextVersion('1.5.0', $newest));
    }

    public function testAnExactVersionMustBeNewerThanTheNewestTag(): void
    {
        $newest = [1, 2, 3];

        $same = Thrown::by(\UnexpectedValueException::class, static fn(): array => Release::nextVersion('1.2.3', $newest));
        $this->assertSame('1.2.3 is not newer than the newest tag, v1.2.3', $same->getMessage());
        Thrown::by(\UnexpectedValueException::class, static fn(): array => Release::nextVersion('1.0.9', $newest));
        $word = Thrown::by(\UnexpectedValueException::class, static fn(): array => Release::nextVersion('next', $newest));
        $this->assertSame('"next" is not patch, minor, major or a version X.Y.Z', $word->getMessage());
    }

    public function testWithNoTagTheWordsCountFromZero(): void
    {
        $this->assertSame([0, 0, 1], Release::nextVersion('patch', null));
        $this->assertSame([0, 1, 0], Release::nextVersion('minor', null));
        $this->assertSame([1, 0, 0], Release::nextVersion('major', null));
        $this->assertSame([1, 0, 0], Release::nextVersion('1.0.0', null));
    }

    public function testDatingMovesTheEntriesUnderTheVersionAndLinksBothSections(): void
    {
        $dated = Release::dateChangelog(self::CHANGELOG, [1, 1, 0], '2026-12-01', [1, 0, 0]);

        $expected = str_replace(
            ["## [Unreleased]\n", '[Unreleased]: ' . self::REPO . "/compare/v1.0.0...HEAD\n"],
            ["## [Unreleased]\n\n## [1.1.0] - 2026-12-01\n", '[Unreleased]: ' . self::REPO . "/compare/v1.1.0...HEAD\n[1.1.0]: " . self::REPO . "/compare/v1.0.0...v1.1.0\n"],
            self::CHANGELOG,
        );
        $this->assertSame($expected, $dated);
        $this->assertSame(['2026-12-01', "### Added\n\n- A feature."], Release::datedSection($dated, [1, 1, 0]));
        $this->assertSame([1, 1, 0], Release::newestDated($dated));
    }

    public function testTheFirstReleaseLinksItsTag(): void
    {
        $first = "## [Unreleased]\n\n- One.\n\n[Unreleased]: " . self::REPO . "/commits/main\n";

        $this->assertSame(
            "## [Unreleased]\n\n## [1.0.0] - 2026-10-22\n\n- One.\n\n[Unreleased]: " . self::REPO . "/compare/v1.0.0...HEAD\n[1.0.0]: " . self::REPO . "/releases/tag/v1.0.0\n",
            Release::dateChangelog($first, [1, 0, 0], '2026-10-22', null),
        );
    }

    /** @return iterable<string, array{string, string, non-empty-string}> a CHANGELOG, a date, and the start of the error */
    public static function changelogsThatCannotBeDated(): iterable
    {
        yield 'an empty Unreleased section' => ["## [Unreleased]\n\n### Added\n\n## [1.0.0] - 2026-10-22\n\n- One.\n\n[Unreleased]: x\n", '2026-12-01', 'the ## [Unreleased] section of CHANGELOG.md has no entry'];
        yield 'a date that is not YYYY-MM-DD' => [self::CHANGELOG, '2026-12-1', '"2026-12-1" is not a date YYYY-MM-DD'];
        yield 'no Unreleased section' => ["# Changelog\n", '2026-12-01', 'CHANGELOG.md has no ## [Unreleased] section'];
        yield 'no Unreleased link' => ["## [Unreleased]\n\n- One.\n", '2026-12-01', 'CHANGELOG.md has no [Unreleased] link'];
        yield 'a section of the version already' => [str_replace('## [1.0.0]', '## [1.0.1]', self::CHANGELOG), '2026-12-01', 'CHANGELOG.md already has a section for 1.0.1'];
    }

    /** @param non-empty-string $error */
    #[DataProvider('changelogsThatCannotBeDated')]
    public function testDatingRefusesAChangelogItCannotDate(string $changelog, string $date, string $error): void
    {
        $thrown = Thrown::by(\UnexpectedValueException::class, static fn(): string => Release::dateChangelog($changelog, [1, 0, 1], $date, [1, 0, 0]));

        $this->assertStringStartsWith($error, $thrown->getMessage());
    }

    public function testASectionWithoutAValidDateIsNotDated(): void
    {
        $this->assertSame('2026-10-22', Release::datedSection(self::CHANGELOG, [1, 0, 0])[0] ?? null);
        $this->assertNull(Release::datedSection("## [1.0.0] - soon\n\n- One.\n", [1, 0, 0]));
        $this->assertNull(Release::datedSection(self::CHANGELOG, [9, 9, 9]));
    }

    public function testTheNewestDatedSectionIsTheFirstOne(): void
    {
        $this->assertSame([1, 0, 0], Release::newestDated(self::CHANGELOG));
        $this->assertSame([1, 1, 0], Release::newestDated("## [Unreleased]\n\n## [1.1.0] - 2026-10-23\n\n## [1.0.0] - 2026-10-22\n"));
        $this->assertNull(Release::newestDated("## [Unreleased]\n\n## [1.1.0] - soon\n"));
    }

    public function testMainRejectsUnknownArgumentsWithTheUsage(): void
    {
        foreach ([[], ['publish'], ['prepare', 'patch'], ['status', 'now'], ['notes']] as $args) {
            $this->assertSame([2, Release::USAGE], Release::main($args, self::ROOT), implode(' ', $args));
        }
    }

    /** The workflow runs the script before `composer install`: it needs PHP alone. */
    #[MaximumDuration(1000)] // starts PHP
    public function testTheScriptRunsWithoutComposer(): void
    {
        [$code, $stdout, $stderr] = $this->command([\PHP_BINARY, '-n', self::ROOT . '/tests/bin/release.php', 'notes', '1.0.0'], self::ROOT);

        $this->assertSame(0, $code, $stderr);
        $this->assertSame(Release::main(['notes', '1.0.0'], self::ROOT)[1] . "\n", $stdout);

        [$code, , $stderr] = $this->command([\PHP_BINARY, '-n', self::ROOT . '/tests/bin/release.php', 'publish'], self::ROOT);
        $this->assertSame(2, $code);
        $this->assertSame(Release::USAGE . "\n", $stderr);
    }

    /** The three steps on a git repository, as the workflow runs them. */
    #[MaximumDuration(3000)] // runs git
    public function testPrepareStatusAndNotesOnARepository(): void
    {
        $dir = $this->repository(self::CHANGELOG, ['v1.0.0']);
        $this->assertSame([0, '1.0.0 publish'], Release::main(['status'], $dir), 'the tag of the newest section is on this commit');
        $this->commit($dir);
        $this->assertSame([0, '1.0.0 skip'], Release::main(['status'], $dir), 'the tag of the newest section is on another commit');

        $this->assertSame([0, '1.0.1'], Release::main(['prepare', 'patch', '2026-12-01'], $dir));
        $this->assertSame([0, '1.0.1 publish'], Release::main(['status'], $dir));
        $this->assertSame([0, "### Added\n\n- A feature."], Release::main(['notes', '1.0.1'], $dir));
        [$code, $text] = Release::main(['prepare', 'patch', '2026-12-02'], $dir);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('has no entry', $text);

        // The merge commit of the release, tagged: a re-run of its release continues it.
        $this->commit($dir);
        $this->git($dir, 'tag', '-a', 'v1.0.1', '-m', 'v1.0.1');
        $this->assertSame([0, '1.0.1 publish'], Release::main(['status'], $dir));

        // The next commit on main: the release is done.
        $this->commit($dir);
        $this->assertSame([0, '1.0.1 skip'], Release::main(['status'], $dir));

        $this->assertSame([1, 'release: CHANGELOG.md has no dated section for 9.9.9'], Release::main(['notes', '9.9.9'], $dir));
        $this->assertSame([1, 'release: "v1.0.1" is not a version X.Y.Z'], Release::main(['notes', 'v1.0.1'], $dir));
        $this->assertSame([1, 'release: 1.0.1 is not newer than the newest tag, v1.0.1'], Release::main(['prepare', '1.0.1', '2026-12-02'], $dir));
    }

    #[MaximumDuration(3000)] // runs git
    public function testStatusFailsWithoutADatedSection(): void
    {
        $dir = $this->repository("## [Unreleased]\n\n- One.\n\n[Unreleased]: x\n", []);

        $this->assertSame([1, 'release: CHANGELOG.md has no dated section with a version X.Y.Z'], Release::main(['status'], $dir));
    }

    #[MaximumDuration(3000)] // runs git
    public function testStatusFailsOutsideAGitRepository(): void
    {
        $dir = $this->repository(self::CHANGELOG, []);
        FileTree::remove($dir . '/.git');

        [$code, $text] = Release::main(['status'], $dir);
        $this->assertSame(1, $code);
        $this->assertStringStartsWith('release: git tag --list failed: ', $text);
    }

    /**
     * The CHANGELOG of this repository can always go on: with entries under [Unreleased], `prepare` dates them as the
     * patch after the newest dated section, and `status` then publishes it; with none, `prepare` stops.
     */
    #[MaximumDuration(3000)] // runs git
    public function testTheChangelogOfThisRepositoryCanBeReleased(): void
    {
        $changelog = (string) file_get_contents(self::ROOT . '/' . Release::CHANGELOG);
        $newest = Release::newestDated($changelog);
        $this->assertNotNull($newest, 'the CHANGELOG has a dated section');
        $dir = $this->repository($changelog, ['v' . Release::format($newest)]);
        $next = Release::format(Release::nextVersion('patch', $newest));

        [$code, $text] = Release::main(['prepare', 'patch', '2026-11-17'], $dir);
        if ($code === 1) {
            $this->assertSame('release: the ## [Unreleased] section of CHANGELOG.md has no entry; add the changes of the release first', $text);

            return;
        }
        $this->assertSame([0, $next], [$code, $text]);
        $this->assertSame([0, $next . ' publish'], Release::main(['status'], $dir));
        [$code, $notes] = Release::main(['notes', $next], $dir);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('### ', $notes);
        $this->assertStringNotContainsString('## [', $notes);
        $this->assertStringContainsString(sprintf('[%s]: %s/compare/v%s...v%s', $next, self::REPO, Release::format($newest), $next), (string) file_get_contents($dir . '/' . Release::CHANGELOG));
    }

    /** @param list<string> $tags */
    private function repository(string $changelog, array $tags): string
    {
        $dir = sys_get_temp_dir() . '/agentcat-release-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->dirs[] = $dir;
        file_put_contents($dir . '/' . Release::CHANGELOG, $changelog);
        $this->git($dir, 'init', '-q');
        $this->commit($dir);
        foreach ($tags as $tag) {
            $this->git($dir, 'tag', '-a', $tag, '-m', $tag);
        }

        return $dir;
    }

    private function commit(string $dir): void
    {
        $this->git($dir, 'add', '-A');
        $this->git($dir, 'commit', '-q', '--allow-empty', '-m', 'commit');
    }

    private function git(string $dir, string ...$args): void
    {
        [$code, , $stderr] = $this->command(['git', '-c', 'user.name=t', '-c', 'user.email=t@example.com', '-c', 'tag.gpgSign=false', '-c', 'commit.gpgSign=false', ...array_values($args)], $dir);
        $this->assertSame(0, $code, 'git ' . implode(' ', $args) . ': ' . $stderr);
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string, string} the exit code, stdout and stderr
     */
    private function command(array $command, string $dir): array
    {
        $process = new Process($command, $dir);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }
}
