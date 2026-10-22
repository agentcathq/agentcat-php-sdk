<?php

declare(strict_types=1);

namespace AgentCat\Tests\Architecture;

use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\TestCase;

/**
 * Each acceptance criterion of `tests/Traceability/criteria.php` has a test that carries its ID as an `ac-` group, or
 * `tests/Traceability/gaps.php` says why no test proves it. Each `ac-` group of a test names a criterion of the list.
 * Only attributes count: a group in a comment or a string is no test.
 */
final class TraceabilityTest extends TestCase
{
    /** `ac-<file>-<criterion>`: a number, a letter and a number, or `g` and a number for a golden body. */
    private const string ID = '/\Aac-0[1-7]-(?:[1-9][0-9]*|[A-Z][1-9][0-9]*|g[1-9])\z/';

    private const int TITLE_LENGTH = 120;

    /** Tokens inside an attribute that are not code. */
    private const array NOT_CODE = [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT];

    /** Tokens that can name the attribute class: `Group`, `Attributes\Group`, `\PHPUnit\…\Group`. */
    private const array NAMES = [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED];

    /** @var array<string, list<string>>|null the groups found by the first scan of this run: the files do not change */
    private static ?array $scanned = null;

    #[MaximumDuration(2000)] // the first of these tests reads every test file
    public function testEveryCriterionHasATestOrAStatedGap(): void
    {
        $tested = $this->acGroups();
        $this->assertNotSame([], $tested, 'the scan finds the ac- groups of the tests');

        $missing = array_values(array_diff(array_keys($this->criteria()), array_keys($tested), array_keys($this->gaps())));

        $this->assertSame([], $missing, "These criteria have no test and no stated gap:\n" . implode("\n", $missing));
    }

    #[MaximumDuration(2000)] // the first of these tests reads every test file
    public function testEveryGroupNamesACriterion(): void
    {
        $unknown = $this->places(array_diff_key($this->acGroups(), $this->criteria()));

        $this->assertSame([], $unknown, "These ac- groups name no criterion:\n" . implode("\n", $unknown));
    }

    #[MaximumDuration(2000)] // the first of these tests reads every test file
    public function testACriterionWithATestIsNoGap(): void
    {
        $both = $this->places(array_intersect_key($this->acGroups(), $this->gaps()));

        $this->assertSame([], $both, "These gaps have a test now; remove them from gaps.php:\n" . implode("\n", $both));
    }

    public function testEveryGapIsACriterionWithAReason(): void
    {
        $gaps = $this->gaps();

        $this->assertSame([], array_keys(array_diff_key($gaps, $this->criteria())), 'every gap is a criterion');
        foreach ($gaps as $id => $reason) {
            $this->assertNotSame('', trim($reason), $id . ' states its reason');
            $this->assertStringNotContainsString("\n", $reason, $id . ': the reason is one line');
        }
    }

    public function testEveryCriterionHasAnIdAndAOneLineTitle(): void
    {
        $criteria = $this->criteria();
        $this->assertNotSame([], $criteria);

        foreach ($criteria as $id => $title) {
            $this->assertMatchesRegularExpression(self::ID, $id);
            $this->assertNotSame('', trim($title), $id . ' has a title');
            $this->assertStringNotContainsString("\n", $title, $id . ': the title is one line');
            $this->assertLessThanOrEqual(self::TITLE_LENGTH, mb_strlen($title), $id . ': the title has at most 120 characters');
        }
    }

    public function testOnlyTheGroupAttributesOfTheCodeCount(): void
    {
        $source = <<<'PHP_WRAP'
            <?php
            // #[Group('ac-01-1')] in a line comment
            /** #[Group('ac-01-2')] in a docblock */
            $text = "#[Group('ac-01-3')] in a string";
            #[Group('ac-01-4'), DataProvider('rows')]
            #[\PHPUnit\Framework\Attributes\Group(name: "ac-01-5")]
            #[Group('runtime'), Attributes\Group(
                'ac-01-6', // a comment inside the attribute
            )]
            #[Covers([Group::class]), Group('ac-01-7')]
            function proved(): void {}
            PHP_WRAP;

        $this->assertSame([['ac-01-4', 5], ['ac-01-5', 6], ['ac-01-6', 8], ['ac-01-7', 10]], $this->groupsIn($source));
    }

    /**
     * Every `ac-` group attribute in the PHP files under `tests/`, with the `file:line` of each use.
     *
     * @return array<string, list<string>>
     */
    private function acGroups(): array
    {
        return self::$scanned ??= $this->scan();
    }

    /** @return array<string, list<string>> */
    private function scan(): array
    {
        $root = dirname(__DIR__);
        $groups = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $this->assertInstanceOf(\SplFileInfo::class, $file);
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            $this->assertIsString($source, $file->getPathname());
            foreach ($this->groupsIn($source) as [$id, $line]) {
                $groups[$id][] = substr($file->getPathname(), strlen($root) + 1) . ':' . $line;
            }
        }
        ksort($groups);

        return $groups;
    }

    /**
     * The `ac-` names of the group attributes of PHP source, with their lines. A comment or a string holds no
     * attribute token, so a group written there does not count.
     *
     * @return list<array{string, int}>
     */
    private function groupsIn(string $source): array
    {
        $groups = [];
        $tokens = token_get_all($source);
        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === \T_ATTRIBUTE) {
                array_push($groups, ...$this->groupsOf($this->attribute($tokens, $index + 1)));
            }
        }

        return $groups;
    }

    /**
     * The code tokens of an attribute, from `$start` (after its `#[`) to its closing bracket.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return list<array{int, string, int}|string>
     */
    private function attribute(array $tokens, int $start): array
    {
        $code = [];
        $depth = 1;
        foreach (array_slice($tokens, $start) as $token) {
            $depth += match ($token) {
                '[' => 1,
                ']' => -1,
                default => 0,
            };
            if ($depth === 0) {
                break;
            }
            if (!is_array($token) || !in_array($token[0], self::NOT_CODE, true)) {
                $code[] = $token;
            }
        }

        return $code;
    }

    /**
     * The `ac-` names that the `Group(…)` calls of one attribute pass as their first argument, with their lines.
     *
     * @param list<array{int, string, int}|string> $code
     *
     * @return list<array{string, int}>
     */
    private function groupsOf(array $code): array
    {
        $groups = [];
        foreach ($code as $index => $token) {
            $isGroup = is_array($token) && in_array($token[0], self::NAMES, true) && ($token[1] === 'Group' || str_ends_with($token[1], '\Group'));
            if (!$isGroup || ($code[$index + 1] ?? null) !== '(') {
                continue;
            }
            $argument = $code[$index + 2] ?? null;
            if (is_array($argument) && $argument[1] === 'name' && ($code[$index + 3] ?? null) === ':') {
                $argument = $code[$index + 4] ?? null;
            }
            if (is_array($argument) && $argument[0] === \T_CONSTANT_ENCAPSED_STRING && str_starts_with(substr($argument[1], 1), 'ac-')) {
                $groups[] = [substr($argument[1], 1, -1), $argument[2]];
            }
        }

        return $groups;
    }

    /**
     * @param array<string, list<string>> $groups
     *
     * @return list<string> `id at file:line`, one per use
     */
    private function places(array $groups): array
    {
        $places = [];
        foreach ($groups as $id => $uses) {
            foreach ($uses as $use) {
                $places[] = $id . ' at ' . $use;
            }
        }

        return $places;
    }

    /** @return array<string, string> */
    private function criteria(): array
    {
        return $this->load('criteria.php');
    }

    /** @return array<string, string> */
    private function gaps(): array
    {
        return $this->load('gaps.php');
    }

    /** @return array<string, string> */
    private function load(string $file): array
    {
        $list = require __DIR__ . '/../Traceability/' . $file;
        $this->assertIsArray($list, $file);
        $entries = [];
        foreach ($list as $id => $text) {
            $this->assertIsString($id, $file);
            $this->assertIsString($text, $file);
            $entries[$id] = $text;
        }

        return $entries;
    }
}
