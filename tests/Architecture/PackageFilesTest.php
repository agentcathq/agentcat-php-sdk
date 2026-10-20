<?php

declare(strict_types=1);

namespace AgentCat\Tests\Architecture;

use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\TestCase;

/**
 * The files that ship next to `src/`: the changelog, the Composer metadata and the Laravel Boost guidelines, which
 * Boost loads from `resources/boost/guidelines/core.blade.php` into the AI agent of a Laravel application. And the
 * comments of every committed file state their rules: the documents the project was planned in are not public.
 */
final class PackageFilesTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    /** The header of Keep a Changelog 1.1.0, line for line. */
    private const array CHANGELOG_HEADER = [
        '# Changelog',
        '',
        'All notable changes to this project will be documented in this file.',
        '',
        'The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),',
        'and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).',
        '',
    ];

    /** The only kinds of change Keep a Changelog 1.1.0 has, in its order. */
    private const array CHANGE_TYPES = ['Added', 'Changed', 'Deprecated', 'Removed', 'Fixed', 'Security'];

    private const string REPOSITORY = 'https://github.com/agentcathq/agentcat-php-sdk/';

    /** The `description` of the TypeScript SDK's package.json (agentcat-typescript-sdk 9b56e9e). */
    private const string TYPESCRIPT_DESCRIPTION = 'Analytics tool for MCP (Model Context Protocol) servers and AI agents - tracks tool usage patterns and provides insights';

    /** The `keywords` of the TypeScript SDK's package.json (agentcat-typescript-sdk 9b56e9e). */
    private const array TYPESCRIPT_KEYWORDS = [
        'ai', 'authentication', 'mcp', 'observability', 'ai-agents', 'ai-platform', 'ai-agent', 'mcps', 'aiagents',
        'ai-agent-tools', 'mcp-servers', 'mcp-server', 'mcp-tools', 'agent-runtime', 'mcp-framework', 'mcp-analytics',
        'agentcat', 'agentcat-analytics',
    ];

    private const array PHP_KEYWORDS = ['php', 'laravel', 'symfony', 'mcp-sdk'];

    private const string GUIDELINE = self::ROOT . '/resources/boost/guidelines/core.blade.php';

    /** The committed paths whose text must not point at the planning documents. */
    private const array PUBLIC_PATHS = ['src', 'tests', 'config', 'resources', '.github', 'README.md', 'CHANGELOG.md', 'CONTRIBUTING.md'];

    /** This file: it holds the pattern and the references that prove it. */
    private const string SELF = 'tests/Architecture/PackageFilesTest.php';

    /**
     * A reference to a planning document: its paths, its ledgers, numbered header and spec conflicts, review focus
     * items, numbered rulings, plan and task numbers, an appendix, numbered findings and open questions, a numbered
     * section of an audit document, and the short IDs of review notes as references (`(C6)`, `A4:`, `N10)`). Decision
     * IDs (`D07`), bug IDs (`B10`) and criterion IDs (`ac-05-E5`) stay: the code names them as rules. Each alternative
     * needs a number or an ID next to its word, so ordinary words ("a plan", "the header", "a task") never match.
     */
    private const string PLANNING_REFERENCE = <<<'REGEX'
        ~
          docs/(?:audit|superpowers) | \.superpowers\b
        | \b(?:[Pp]lan\s+[A-F][0-9]? | [A-F][0-9])\s+ledgers?\b
        | \b(?:[Hh]eader|[Ss]pec)\s+conflicts?\s+[0-9]
        | \b[Rr]eview[\s-]+[Ff]ocus\b
        | \b[Rr]ulings?\s+[A-Z][0-9]+\b | \b[A-Z][0-9]+\s+ruling\b
        | \bPlans?\s+[A-F][0-9]?\b | \bplans?\s+[A-F][0-9]\b
        | \bTasks?\s+[0-9]+\b
        | \b[Aa]ppendix\s+[A-Z]\b
        | \b[Ff]indings?\s+[A-Z]?[0-9]+\b
        | \b[Oo]pen\s+questions?\s+[A-Z]?[0-9]+\b
        | \b[Aa]udit\s+[0-9]
        | \bDECISIONS\b
        | (?<![\w$\\'{}-])[ACEFIMNQ][0-9]{1,2}(?=:|\)|\s+(?:of|and|header|carry|review|ruling)\b)
        | (?:\bREADME | \b0[0-9][a-c]? | `0[0-9][a-c]?`)\s+\x{00A7}
        ~xu
        REGEX;

    public function testTheChangelogStartsWithTheKeepAChangelogHeader(): void
    {
        $lines = $this->lines(self::ROOT . '/CHANGELOG.md');

        $this->assertSame(self::CHANGELOG_HEADER, array_slice($lines, 0, count(self::CHANGELOG_HEADER)));
        $this->assertSame('## [Unreleased]', $lines[count(self::CHANGELOG_HEADER)] ?? null, 'the next release collects its changes under Unreleased, on top');
    }

    public function testEveryReleaseHasADateAndEveryChangeAKnownType(): void
    {
        foreach ($this->lines(self::ROOT . '/CHANGELOG.md') as $line) {
            if (str_starts_with($line, '## ')) {
                $this->assertMatchesRegularExpression('/\A## \[(?:Unreleased\]|\d+\.\d+\.\d+\] - \d{4}-\d{2}-\d{2})\z/', $line, 'a release is "[Unreleased]" or "[x.y.z] - YYYY-MM-DD"');
            }
        }
        $releases = $this->releases();
        $this->assertNotSame([], $releases);

        foreach ($releases as $name => $sections) {
            $types = array_keys($sections);
            $this->assertSame(array_values(array_intersect(self::CHANGE_TYPES, $types)), $types, sprintf('release %s: only the Keep a Changelog types, in their order, once each', $name));
            if ($name !== 'Unreleased') {
                $this->assertNotSame([], $sections, sprintf('release %s lists its changes', $name));
            }
            foreach ($sections as $type => $items) {
                $this->assertNotSame([], $items, sprintf('release %s: %s lists at least one change', $name, $type));
            }
        }
    }

    /** The first release, Unreleased until it has its version, is the last one in the file. */
    public function testTheFirstReleaseListsItsFeaturesAndTheB10Change(): void
    {
        $releases = $this->releases();
        $first = end($releases);
        $this->assertIsArray($first);

        $this->assertCount(10, $first['Added'] ?? [], 'one line for each feature of 1.0.0');
        $changed = $first['Changed'] ?? [];
        $this->assertCount(1, $changed);
        $this->assertStringStartsWith('Unlike the TypeScript SDK, AgentCat reads every `tools/list` page, so no listed tool falls back to the heuristic argument strip (B10).', $changed[0]);
        $this->assertStringContainsString('57.6 ms (2.7 ms before)', $changed[0], 'the change states its cost');
    }

    public function testTheLinksOfTheReleasesEndTheChangelog(): void
    {
        $lines = $this->lines(self::ROOT . '/CHANGELOG.md');
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        $names = array_keys($this->releases());

        $links = array_slice($lines, -count($names));
        $this->assertCount(count($names), $links);
        foreach ($names as $index => $name) {
            $this->assertStringStartsWith(sprintf('[%s]: %s', $name, self::REPOSITORY), $links[$index] ?? '', 'one link for each release, in the order of the releases');
        }
        $this->assertSame('', $lines[count($lines) - count($names) - 1] ?? null, 'a blank line before the links');
    }

    public function testTheComposerMetadataIsTheTypeScriptPackageMetadata(): void
    {
        $composer = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($composer);

        $this->assertSame(self::TYPESCRIPT_DESCRIPTION, $composer['description'] ?? null);
        $keywords = $composer['keywords'] ?? null;
        $this->assertIsList($keywords);
        $strings = array_values(array_filter($keywords, is_string(...)));
        $this->assertSame($keywords, $strings, 'every keyword is a string');
        $keywords = $strings;
        $this->assertSame([], array_values(array_diff([...self::TYPESCRIPT_KEYWORDS, ...self::PHP_KEYWORDS], $keywords)), 'every TypeScript keyword, and the PHP ones');
        $this->assertSame(array_values(array_unique($keywords)), $keywords, 'each keyword once');
    }

    public function testTheBoostGuidelineNamesTheSetup(): void
    {
        $guideline = $this->guideline();

        foreach (['composer require agentcat/sdk', 'AGENTCAT_PROJECT_ID', 'php artisan agentcat:install', 'AgentCat::fake()', 'php artisan vendor:publish --tag=agentcat-config', 'https://docs.agentcat.com/sdk/php/laravel'] as $text) {
            $this->assertStringContainsString($text, $guideline);
        }
    }

    public function testTheBoostGuidelineNamesOnlyKeysOfTheConfiguration(): void
    {
        $keys = require self::ROOT . '/config/agentcat.php';
        $this->assertIsArray($keys);
        $guideline = $this->guideline();
        $this->assertSame(1, preg_match('/^### Configuration\n(.*?)^### /ms', $guideline, $section), 'the guideline has a Configuration section');
        preg_match_all('/^- (.+?): /m', $section[1], $items);
        $named = [];
        foreach ($items[1] as $item) {
            preg_match_all('/`([a-z]+(?:_[a-z]+)*)`/', $item, $codes);
            array_push($named, ...$codes[1]);
        }

        $this->assertContains('exclude', $named, 'the guideline lists the keys of config/agentcat.php');
        $this->assertContains('identify', $named);
        foreach ($named as $key) {
            $this->assertArrayHasKey($key, $keys, 'a key the guideline names is a key of config/agentcat.php');
        }
    }

    /** Blade compiles the guideline to itself: no `@` directive, echo or PHP tag in the Markdown by accident. */
    public function testBladeLeavesTheGuidelineAsItIs(): void
    {
        $guideline = $this->guideline();
        $compiler = new BladeCompiler(new Filesystem(), self::ROOT . '/.cache/blade'); // compileString() writes nothing

        $this->assertSame($guideline, $compiler->compileString($guideline));
    }

    public function testTheChangelogAndTheGuidelinesShipAndTheContributingGuideDoesNot(): void
    {
        $attributes = array_map(trim(...), $this->lines(self::ROOT . '/.gitattributes'));

        $this->assertContains('/CONTRIBUTING.md export-ignore', $attributes);
        foreach ($attributes as $line) {
            $this->assertDoesNotMatchRegularExpression('#\A/?(?:CHANGELOG\.md|resources)\b#', $line, 'CHANGELOG.md and resources/ go into the dist');
        }
    }

    /** A comment states its rule: no committed file points at the documents the project was planned in. */
    #[MaximumDuration(3000)] // reads every file of the public paths
    public function testNoCommittedFileNamesThePlanningDocuments(): void
    {
        $found = [];
        $files = 0;
        foreach (self::PUBLIC_PATHS as $path) {
            foreach ($this->files(self::ROOT . '/' . $path) as $file) {
                if (str_ends_with($file, '/' . self::SELF)) {
                    continue;
                }
                ++$files;
                $text = file_get_contents($file);
                $this->assertIsString($text, $file);
                if (preg_match('//u', $text) !== 1) {
                    continue; // not UTF-8 text: an image or another binary fixture
                }
                // A reference that wraps onto the next comment line still counts: the line breaks become spaces.
                $joined = (string) preg_replace('~\\R[ \\t]*(?:\\*(?!/)|//|#)?[ \\t]*~u', ' ', $text);
                if (preg_match_all(self::PLANNING_REFERENCE, $joined, $matches) > 0) {
                    $found[] = sprintf('%s: %s', substr($file, strlen(self::ROOT) + 1), implode(', ', array_unique($matches[0])));
                }
            }
        }

        $this->assertGreaterThan(500, $files, 'the scan reads the public paths');
        $this->assertSame([], $found, "These files point at planning documents; state the rule instead:\n" . implode("\n", $found));
    }

    /** The pattern finds each kind of reference, and not the IDs and words that stay. */
    public function testThePlanningReferencePatternFindsReferencesOnly(): void
    {
        $references = [
            'plan E1', 'Plan F', 'Task 13', 'header conflict 3', 'Spec conflicts 1', 'Review Focus 2',
            'review-focus-2', 'ruling P8', 'Ruling R4', 'the E2 ledger', 'Appendix A', 'finding F1',
            'open question Q3', 'audit 3.3', 'DECISIONS item 6', 'docs/audit/02-engine.md', '(C6)',
            'A4: the opt-out', 'N10)', "02 \u{00A7}3.3", "README \u{00A7}4.5", "`07` \u{00A7}6.7",
        ];
        foreach ($references as $reference) {
            $this->assertSame(1, preg_match(self::PLANNING_REFERENCE, $reference), $reference);
        }
        $kept = [
            'D07', 'D66, B10 fixed', 'ac-05-E5', "'ac-05-C2'", "RFC 9110 \u{00A7}8.6", '$e1 = new \\Exception()',
            "pack('C4', \$bytes)", '\\u{a1}A9\\u{5b}', 'E2E', 'a secret plan', 'the plan is pro',
            'tasks in the queue', 'composer audit', 'the header line', 'a ruler', 'mcp/sdk 0.9.0', 'PHP 8.5',
            'Swoole 6.2', 'a task 3 s later', 'a plan B', 'A ledger of payments', 'two header conflicts',
            'the court ruling', 'an Apple M2 chip', 'Q3 2026', 'Task: none',
        ];
        foreach ($kept as $text) {
            $this->assertSame(0, preg_match(self::PLANNING_REFERENCE, $text), $text);
        }
    }

    /** @return list<string> the files at `$path`, recursively */
    private function files(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }
        $this->assertDirectoryExists($path);
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function guideline(): string
    {
        $guideline = file_get_contents(self::GUIDELINE);
        $this->assertIsString($guideline, 'resources/boost/guidelines/core.blade.php exists');

        return $guideline;
    }

    /** @return list<string> */
    private function lines(string $file): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines, $file);

        return $lines;
    }

    /**
     * Each release (the name in the brackets of its heading), with its `### <type>` sections and their `- ` items; an
     * indented line continues the item before it.
     *
     * @return array<string, array<string, list<string>>>
     */
    private function releases(): array
    {
        $releases = [];
        $release = null;
        $type = null;
        foreach ($this->lines(self::ROOT . '/CHANGELOG.md') as $line) {
            if (preg_match('/\A## \[([^\]]+)\]/', $line, $heading) === 1) {
                $release = $heading[1];
                $this->assertArrayNotHasKey($release, $releases, 'each release once');
                $releases[$release] = [];
                $type = null;

                continue;
            }
            if ($release === null) {
                continue;
            }
            $sections = $releases[$release] ?? [];
            if (str_starts_with($line, '### ')) {
                $type = substr($line, 4);
                $this->assertContains($type, self::CHANGE_TYPES, sprintf('release %s: a Keep a Changelog type', $release));
                $this->assertArrayNotHasKey($type, $sections, sprintf('release %s: %s once', $release, $type));
                $sections[$type] = [];
            } elseif ($type !== null) {
                $items = $sections[$type] ?? [];
                if (str_starts_with($line, '- ')) {
                    $items[] = substr($line, 2);
                } elseif (str_starts_with($line, '  ') && $items !== []) {
                    $items[] = array_pop($items) . ' ' . trim($line);
                }
                $sections[$type] = $items;
            }
            $releases[$release] = $sections;
        }

        return $releases;
    }
}
