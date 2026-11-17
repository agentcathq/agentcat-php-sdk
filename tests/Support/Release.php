<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

/**
 * The release steps of `.github/workflows/release.yml`, on `CHANGELOG.md` and the `v*` tags of the repository. The Java
 * SDK (`scripts/Release.java`) and the Rust SDK (`xtask release`) have the same three steps. `tests/bin/release.php`
 * runs them with PHP alone: this class needs no Composer package, and it does not ship (`/tests` is export-ignore).
 *
 * The SDK has no version file: `composer.json` has no `version`, and Packagist reads each version from its `vX.Y.Z`
 * tag. So the dated sections of the CHANGELOG and the tags are the whole state of a release.
 *
 * - `prepare <patch | minor | major | X.Y.Z> <YYYY-MM-DD>` picks the next version, turns the [Unreleased] section of
 *   the CHANGELOG into the section of that version with the date, and prints the version. `patch`, `minor` and
 *   `major` count from the newest `vX.Y.Z` tag (from 0.0.0 with no tag). An exact version must be newer than the
 *   newest tag. An [Unreleased] section with no entry stops the step: a release needs a change.
 * - `status` prints `<version> publish` for the newest dated section of the CHANGELOG when its version has no tag, or
 *   when its tag is on the checked-out commit (a re-run of the release of that commit), else `<version> skip`.
 * - `notes <X.Y.Z>` prints the body of the CHANGELOG section of that version.
 */
final class Release
{
    public const string REPOSITORY = 'https://github.com/agentcathq/agentcat-php-sdk';

    public const string CHANGELOG = 'CHANGELOG.md';

    public const string USAGE = 'usage: php tests/bin/release.php prepare <patch | minor | major | X.Y.Z> <YYYY-MM-DD> | status | notes <X.Y.Z>';

    private const string UNRELEASED = '## [Unreleased]';

    /** MAJOR.MINOR.PATCH with decimal numbers and no leading zero: no pre-release or build part. */
    private const string VERSION = '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\z/';

    private const string DATE = '/\A\d{4}-\d{2}-\d{2}\z/';

    /** A dated section heading: `## [X.Y.Z] - YYYY-MM-DD`. */
    private const string DATED_HEADING = '/\A## \[([^\]]+)\] - (\d{4}-\d{2}-\d{2})\z/';

    /**
     * Runs one step. Returns the exit code and the text for stdout (code 0) or stderr.
     *
     * @param list<string> $args
     *
     * @return array{int, string}
     */
    public static function main(array $args, string $root): array
    {
        try {
            $output = match (true) {
                count($args) === 3 && $args[0] === 'prepare' => self::prepare($root, $args[1], $args[2]),
                $args === ['status'] => self::status($root),
                count($args) === 2 && $args[0] === 'notes' => self::notes($root, $args[1]),
                default => null,
            };
        } catch (\UnexpectedValueException $e) {
            return [1, 'release: ' . $e->getMessage()];
        }

        return $output === null ? [2, self::USAGE] : [0, $output];
    }

    /** @return array{int, int, int}|null */
    public static function parse(string $text): ?array
    {
        if (preg_match(self::VERSION, $text, $parts) !== 1) {
            return null;
        }

        return [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
    }

    /** @param array{int, int, int} $version */
    public static function format(array $version): string
    {
        return implode('.', $version);
    }

    /**
     * The newest of the `v<version>` tags; other tags (pre-releases, tags of other modules) do not count.
     *
     * @param list<string> $tags
     *
     * @return array{int, int, int}|null
     */
    public static function newestTag(array $tags): ?array
    {
        $newest = null;
        foreach ($tags as $tag) {
            $version = str_starts_with($tag, 'v') ? self::parse(substr($tag, 1)) : null;
            if ($version !== null && ($newest === null || $version > $newest)) {
                $newest = $version;
            }
        }

        return $newest;
    }

    /**
     * The version of the release that `$input` asks for: see the comment of the class.
     *
     * @param array{int, int, int}|null $newest
     *
     * @return array{int, int, int}
     */
    public static function nextVersion(string $input, ?array $newest): array
    {
        [$major, $minor, $patch] = $newest ?? [0, 0, 0];
        $next = match ($input) {
            'patch' => [$major, $minor, $patch + 1],
            'minor' => [$major, $minor + 1, 0],
            'major' => [$major + 1, 0, 0],
            default => self::parse($input) ?? throw new \UnexpectedValueException(sprintf('"%s" is not patch, minor, major or a version X.Y.Z', $input)),
        };
        if ($newest !== null && $next <= $newest) {
            throw new \UnexpectedValueException(sprintf('%s is not newer than the newest tag, v%s', self::format($next), self::format($newest)));
        }

        return $next;
    }

    /**
     * `$changelog` with its [Unreleased] entries moved under `## [<version>] - <date>`, an empty [Unreleased] section
     * above it, and the links of both sections.
     *
     * @param array{int, int, int} $version
     * @param array{int, int, int}|null $previous the newest tag
     */
    public static function dateChangelog(string $changelog, array $version, string $date, ?array $previous): string
    {
        if (preg_match(self::DATE, $date) !== 1) {
            throw new \UnexpectedValueException(sprintf('"%s" is not a date YYYY-MM-DD', $date));
        }
        $name = self::format($version);
        $lines = self::lines($changelog);
        $start = array_search(self::UNRELEASED, $lines, true);
        if (!is_int($start)) {
            throw new \UnexpectedValueException(sprintf('%s has no %s section', self::CHANGELOG, self::UNRELEASED));
        }
        $entries = array_slice($lines, $start + 1, self::sectionEnd($lines, $start) - $start - 1);
        if (preg_grep('/\A- /', $entries) === []) {
            throw new \UnexpectedValueException(sprintf('the %s section of %s has no entry; add the changes of the release first', self::UNRELEASED, self::CHANGELOG));
        }
        foreach ($lines as $line) {
            if (str_starts_with($line, '## [' . $name . ']')) {
                throw new \UnexpectedValueException(sprintf('%s already has a section for %s', self::CHANGELOG, $name));
            }
        }
        $link = null;
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, '[Unreleased]: ')) {
                $link = $index;

                break;
            }
        }
        if ($link === null) {
            throw new \UnexpectedValueException(sprintf('%s has no [Unreleased] link', self::CHANGELOG));
        }
        $versionLink = $previous === null
            ? sprintf('[%s]: %s/releases/tag/v%s', $name, self::REPOSITORY, $name)
            : sprintf('[%s]: %s/compare/v%s...v%s', $name, self::REPOSITORY, self::format($previous), $name);

        $out = [];
        foreach ($lines as $index => $line) {
            if ($index === $start) {
                $out[] = self::UNRELEASED;
                $out[] = '';
                $out[] = sprintf('## [%s] - %s', $name, $date);
            } elseif ($index === $link) {
                $out[] = sprintf('[Unreleased]: %s/compare/v%s...HEAD', self::REPOSITORY, $name);
                $out[] = $versionLink;
            } else {
                $out[] = $line;
            }
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * The date and the body of the `## [<version>] - <date>` section, without its blank edges.
     *
     * @param array{int, int, int} $version
     *
     * @return array{string, string}|null
     */
    public static function datedSection(string $changelog, array $version): ?array
    {
        $lines = self::lines($changelog);
        foreach ($lines as $index => $line) {
            if (preg_match(self::DATED_HEADING, $line, $heading) === 1 && $heading[1] === self::format($version)) {
                $body = array_slice($lines, $index + 1, self::sectionEnd($lines, $index) - $index - 1);

                return [$heading[2], trim(implode("\n", $body), "\n")];
            }
        }

        return null;
    }

    /**
     * The version of the first dated section: the newest release of the CHANGELOG.
     *
     * @return array{int, int, int}|null
     */
    public static function newestDated(string $changelog): ?array
    {
        foreach (self::lines($changelog) as $line) {
            if (preg_match(self::DATED_HEADING, $line, $heading) === 1) {
                return self::parse($heading[1]);
            }
        }

        return null;
    }

    private static function prepare(string $root, string $input, string $date): string
    {
        $newest = self::newestTag(self::git($root, 'tag', '--list'));
        $version = self::nextVersion($input, $newest);
        self::write($root, self::dateChangelog(self::read($root), $version, $date, $newest));

        return self::format($version);
    }

    private static function status(string $root): string
    {
        $version = self::newestDated(self::read($root))
            ?? throw new \UnexpectedValueException(sprintf('%s has no dated section with a version X.Y.Z', self::CHANGELOG));
        $tag = 'v' . self::format($version);
        $tagged = in_array($tag, self::git($root, 'tag', '--list'), true);
        $onHead = in_array($tag, self::git($root, 'tag', '--list', '--points-at', 'HEAD'), true);

        return self::format($version) . (!$tagged || $onHead ? ' publish' : ' skip');
    }

    private static function notes(string $root, string $text): string
    {
        $version = self::parse($text) ?? throw new \UnexpectedValueException(sprintf('"%s" is not a version X.Y.Z', $text));
        $section = self::datedSection(self::read($root), $version)
            ?? throw new \UnexpectedValueException(sprintf('%s has no dated section for %s', self::CHANGELOG, $text));

        return $section[1];
    }

    /**
     * The index of the line after the section that starts at `$start`: the next `## ` heading or the first link line.
     *
     * @param list<string> $lines
     */
    private static function sectionEnd(array $lines, int $start): int
    {
        foreach (array_slice($lines, $start + 1, preserve_keys: true) as $index => $line) {
            if (str_starts_with($line, '## ') || preg_match('/\A\[[^\]]+\]: /', $line) === 1) {
                return $index;
            }
        }

        return count($lines);
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $text));
        if (end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * The lines that `git <args>` prints in `$root`.
     *
     * @return list<string>
     */
    private static function git(string $root, string ...$args): array
    {
        $process = proc_open(['git', ...array_values($args)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        $stdout = $pipes[1] ?? null;
        $stderr = $pipes[2] ?? null;
        if (!is_resource($process) || !is_resource($stdout) || !is_resource($stderr)) {
            throw new \UnexpectedValueException('cannot run git');
        }
        $output = (string) stream_get_contents($stdout);
        $error = (string) stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);
        if (proc_close($process) !== 0) {
            throw new \UnexpectedValueException(sprintf('git %s failed: %s', implode(' ', $args), trim($error)));
        }

        return self::lines($output);
    }

    private static function read(string $root): string
    {
        $path = $root . '/' . self::CHANGELOG;
        $text = is_file($path) ? file_get_contents($path) : false;
        if ($text === false) {
            throw new \UnexpectedValueException('cannot read ' . self::CHANGELOG);
        }

        return $text;
    }

    private static function write(string $root, string $text): void
    {
        if (file_put_contents($root . '/' . self::CHANGELOG, $text) === false) {
            throw new \UnexpectedValueException('cannot write ' . self::CHANGELOG);
        }
    }
}
