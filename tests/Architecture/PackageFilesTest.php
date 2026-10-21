<?php

declare(strict_types=1);

namespace AgentCat\Tests\Architecture;

use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The files that ship next to `src/`: the changelog, the Composer metadata and the Laravel Boost guidelines, which
 * Boost loads from `resources/boost/guidelines/core.blade.php` into the AI agent of a Laravel application. And the
 * comments of every committed file state their rules: the documents the project was planned in are not public. And the
 * PHPUnit baseline of the PHP 8.6 jobs, which does not ship.
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

    /** The committed paths whose text must not point at the planning documents: every directory and root file. */
    private const array PUBLIC_PATHS = [
        'src', 'tests', 'config', 'resources', 'recipe', '.github', 'README.md', 'CHANGELOG.md', 'CONTRIBUTING.md',
        'composer.json', 'composer-dependency-analyser.php', 'phpstan.neon.dist', 'phpstan.no-bundle.neon',
        'phpstan.php85.neon', 'rector.php', 'infection.json5', 'phpunit.xml.dist', 'phpunit-baseline.xml',
        '.php-cs-fixer.dist.php', '.gitattributes', '.gitignore', '.editorconfig',
    ];

    /** This file: it holds the pattern and the texts that prove it. */
    private const string SELF = 'tests/Architecture/PackageFilesTest.php';

    /**
     * A reference to a planning document, in any case. Decision IDs (`D07`), bug IDs (`B10`) and criterion IDs in the
     * spelling of `tests/Traceability/criteria.php` (`ac-05-E5`) stay: the code names them as rules. The words of the
     * planning process match alone; a plan, task, finding, question, appendix or audit section matches with its number
     * or ID, so "a plan" or "Finding 3 items" does not. Phrases that look like a reference but are ordinary are
     * `ALLOWED_PHRASES`.
     */
    private const string PLANNING_REFERENCE = <<<'REGEX'
        ~
          docs/(?:audit|superpowers)\b | \.superpowers\b
        | \bledgers?\b | \brulings?\b | \breview[\s-]+focus\b | \b(?:header|spec)\s+conflicts?\b
        | \bplans?\s+(?:[a-f][0-9]\b | [c-f]\b(?!\.\w))        # Plan C1, plan E2, Plan F; "plan A", "plan B" are idioms
        | \btasks?\s+[0-9]+\b                                  # Task 13; the ordinary phrases are ALLOWED_PHRASES
        | \bappendix\s+[a-z]\b | \bfindings?\s+[a-z][0-9]+\b | \bopen\s+questions?\s+[a-z]?[0-9]+\b
        | \baudit\s+[0-9]+\.[0-9] | (?-i:\bDECISIONS\b)
        | (?:\breadme | \b0[0-9][a-c]? | `0[0-9][a-c]?`)\s+\x{00A7}   # a section of an audit document: 02 §3.3
        | (?:\b|`)0[0-9]`?\s+ac-[0-9]+                                 # a criterion in the audit's spelling: 03 AC-11
        | \((?:[^()\n]*?[\s,])?[acefimn][0-9]{1,2}[,)]                 # a review note in parentheses: (C6), (B16, N10)
        | (?-i:(?<![\w$\\'{}:-])[ACEFIMNQ][0-9]{1,2}:\s)               # a review note that leads a comment: A4: …
        ~xui
        REGEX;

    /**
     * Ordinary phrases that `PLANNING_REFERENCE` would flag, removed before it runs. MCP's own tasks feature numbers a
     * task within its set ("task 2 of an MCP server", "Task 1 of the queue") or says what the task does ("task 2 is
     * cancelled"); another document has its own appendices ("Appendix B of RFC 9110"). A comment that needs another
     * ordinary phrase adds it here, with its reason.
     */
    private const string ALLOWED_PHRASES = <<<'REGEX'
        ~
          \btasks?\s+[0-9]+\s+of\s+(?!plan\b)
        | \btasks?\s+[0-9]+\s+(?:is|was|stays|runs|completes|completed|fails|failed|ends|ended|gets|returns|has)\b
        | \bappendix\s+[a-z]\s+of\s+(?!(?:the|this)\s+plan\b)
        ~xui
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

    /**
     * The first release, Unreleased until it has its version, is the last one in the file. It names only what 1.0.0
     * ships: the Symfony Flex recipe reaches Flex after the release, and its line comes with the release that follows.
     * Its lines speak to customers: no decision or bug ID, which no reader can look up.
     */
    public function testTheFirstReleaseListsItsFeaturesAndTheToolListChange(): void
    {
        $releases = $this->releases();
        $first = end($releases);
        $this->assertIsArray($first);

        $added = $first['Added'] ?? [];
        $this->assertCount(10, $added, 'one line for each feature of 1.0.0');
        $this->assertSame([], preg_grep('/\bFlex\b/i', $added), 'no Flex recipe in 1.0.0');
        $changed = $first['Changed'] ?? [];
        $this->assertCount(1, $changed);
        $this->assertStringStartsWith('AgentCat reads `tools/list` pages until it finds the called tool, so a tool on a later page keeps its own parameters', $changed[0]);
        $this->assertStringContainsString('your own `tools/list` handler', $changed[0], 'the change says whose code runs more often');
        $this->assertStringContainsString('runs once for each page that AgentCat reads during a `tools/call`', $changed[0]);
        $this->assertStringContainsString('its queries, metrics and log lines', $changed[0]);
        $this->assertStringContainsString('57.6 ms (2.7 ms before)', $changed[0], 'the change states its cost');
        foreach ($releases as $sections) {
            foreach ($sections as $items) {
                $this->assertSame([], preg_grep('/\b[A-Z][0-9]{1,2}\b/', $items), 'a decision or bug ID means nothing to a customer');
            }
        }
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

    /** The contributing guide makes no promise of a response time (D70): issues and pull requests get an answer as soon as we can. */
    public function testTheContributingGuidePromisesNoResponseTime(): void
    {
        $guide = (string) file_get_contents(self::ROOT . '/CONTRIBUTING.md');

        $this->assertDoesNotMatchRegularExpression('/\b(?:business\s+)?(?:days?|hours?|weeks?)\b/i', $guide, 'no deadline for an answer');
        $this->assertStringContainsString('as soon as we can', $guide);
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

    /**
     * Only the PHP 8.6 jobs read `phpunit-baseline.xml`, and an unreadable baseline is a PHPUnit warning there, not a
     * failure here. PHPUnit joins the baseline's directory and each path without resolving "..": a path matches only
     * when it names a file from the repository root.
     */
    public function testThePhpunitBaselineIsWellFormedAndNamesFilesFromTheRoot(): void
    {
        $document = new \DOMDocument();
        $errors = libxml_use_internal_errors(true);
        try {
            $loaded = $document->load(self::ROOT . '/phpunit-baseline.xml');
            $problems = array_map(static fn(\LibXMLError $error): string => trim($error->message), libxml_get_errors());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($errors);
        }

        $this->assertTrue($loaded, 'phpunit-baseline.xml is not well-formed: ' . implode('; ', $problems));
        $files = $document->getElementsByTagName('file');
        $this->assertGreaterThan(0, $files->length);
        foreach ($files as $file) {
            $path = $file->getAttribute('path');
            $this->assertStringNotContainsString('..', $path);
            $this->assertFileExists(self::ROOT . '/' . $path);
        }
        $this->assertContains('/phpunit-baseline.xml export-ignore', array_map(trim(...), $this->lines(self::ROOT . '/.gitattributes')));
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
                $references = $this->references((string) preg_replace('~\R[ \t]*(?:\*(?!/)|//|#)?[ \t]*~u', ' ', $text));
                if ($references !== []) {
                    $found[] = sprintf('%s: %s', substr($file, strlen(self::ROOT) + 1), implode(', ', $references));
                }
            }
        }

        $this->assertGreaterThan(500, $files, 'the scan reads the public paths');
        $this->assertSame([], $found, "These files point at planning documents; state the rule instead:\n" . implode("\n", $found));
    }

    /** @return iterable<string, array{string, bool}> a text, and whether it points at a planning document */
    public static function planningTexts(): iterable
    {
        $references = [
            // the families, in any case
            'plan E1', 'Plan F', 'PLAN F', 'plan f', 'PLAN E1', 'plan e1', 'Plans C1 and C2', 'Plan E1 Task 6 parity',
            'Task 13', 'task 13', 'TASK 13', 'Task 4 review M2', 'Task 2',
            'ledger', 'the E2 ledger', 'E2 LEDGER', 'A ledger of payments', 'ruling', 'ruling P8', 'Ruling R4',
            'RULING R4', 'ruling r4', 'the court ruling', 'Review Focus 2', 'REVIEW FOCUS', 'review-focus-2',
            'header conflict 3', 'HEADER CONFLICT 3', 'two header conflicts', 'Spec conflicts 1',
            'docs/audit/02-engine.md', 'DOCS/AUDIT/', 'Docs/Audit/x', 'docs/superpowers/plans', '.superpowers/sdd',
            'Appendix A', 'appendix a', 'Appendix A of the plan', 'finding F1', 'finding f1', 'open question Q3',
            'audit 3.3', 'AUDIT 3.3', 'DECISIONS item 6',
            // audit sections and the audit's spelling of a criterion
            "02 \u{00A7}3.3", "README \u{00A7}4.5", "`07` \u{00A7}6.7", '03 AC-11', '`03` AC-11', 'D07 over 06 AC-61',
            // review-note IDs as references
            '(C6)', '(c6)', '(B16, N10)', '(C6, README', 'A4: the opt-out', 'M3: every bool key', 'Q3: no timer',
        ];
        foreach ($references as $text) {
            yield 'reference: ' . $text => [$text, true];
        }
        $ordinary = [
            // IDs that stay
            'D07', 'D66, B10 fixed', '(D16)', '(D47, D58)', 'ac-05-E5', "'ac-05-C2'", 'ac-03-11',
            "RFC 9110 \u{00A7}8.6",
            // code and data
            '$e1 = new \Exception()', "pack('C4', \$bytes)", '\u{a1}A9\u{5b}', 'E2E', 'A1:A5', 'C4)', 'mcp/sdk 0.9.0',
            'PHP 8.5', 'Swoole 6.2', 'Q3 2026', '(released in Q3)', 'an Apple M2 chip',
            // ordinary words
            'a secret plan', 'the plan is pro', 'a plan B', 'Plan B', 'Plan A is faster', 'a plan, e.g. a fallback',
            'a plan e.g. for B10', 'tasks in the queue', 'Task: none', 'composer audit', 'Audit 2 users',
            'the header line', 'a ruler', 'Finding 3 items', 'Appendix B of RFC 9110',
            // MCP's own tasks feature
            'tasks/list', 'mcp:tasks/cancel', 'notifications/tasks/status', 'a test about task 2 of an MCP server',
            'Task 1 of the queue', 'task 1 of 3', 'task 2 is cancelled',
        ];
        foreach ($ordinary as $text) {
            yield 'ordinary: ' . $text => [$text, false];
        }
    }

    /**
     * Every example of the review: a bare task number counts as a plan reference, as the plan wrote it ("Task 4:",
     * "(Task 6 review)"); MCP's tasks have string IDs, and an ordinal names its set ("task 2 of an MCP server").
     */
    #[DataProvider('planningTexts')]
    public function testThePlanningReferencePatternFindsReferencesOnly(string $text, bool $reference): void
    {
        $this->assertSame($reference, $this->references($text) !== [], implode(', ', $this->references($text)));
    }

    /** @return list<string> the references to planning documents in `$text`, each once */
    private function references(string $text): array
    {
        $text = (string) preg_replace(self::ALLOWED_PHRASES, ' ', $text);
        preg_match_all(self::PLANNING_REFERENCE, $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /** @return list<string> the files at `$path`, recursively */
    private function files(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }
        $this->assertDirectoryExists($path, 'a public path is a file or a directory of the repository');
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
