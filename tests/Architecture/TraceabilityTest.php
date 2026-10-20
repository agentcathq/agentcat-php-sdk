<?php

declare(strict_types=1);

namespace AgentCat\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Each acceptance criterion of `tests/Traceability/criteria.php` has a test that carries its ID as an `ac-` group, or
 * `tests/Traceability/gaps.php` says why no test proves it. Each `ac-` group of a test names a criterion of the list.
 */
final class TraceabilityTest extends TestCase
{
    /** A PHPUnit group attribute whose name starts with `ac-`. */
    private const string GROUP = '/\bGroup\(\s*(?:name:\s*)?([\'"])(ac-[^\'"]*)\1\s*\)/';

    /** `ac-<file>-<criterion>`: a number, a letter and a number, or `g` and a number for a golden body. */
    private const string ID = '/\Aac-0[1-7]-(?:[1-9][0-9]*|[A-Z][1-9][0-9]*|g[1-9])\z/';

    private const int TITLE_LENGTH = 120;

    public function testEveryCriterionHasATestOrAStatedGap(): void
    {
        $tested = $this->acGroups();
        $this->assertNotSame([], $tested, 'the scan finds the ac- groups of the tests');

        $missing = array_values(array_diff(array_keys($this->criteria()), array_keys($tested), array_keys($this->gaps())));

        $this->assertSame([], $missing, "These criteria have no test and no stated gap:\n" . implode("\n", $missing));
    }

    public function testEveryGroupNamesACriterion(): void
    {
        $unknown = [];
        foreach (array_diff_key($this->acGroups(), $this->criteria()) as $id => $places) {
            foreach ($places as $place) {
                $unknown[] = $id . ' at ' . $place;
            }
        }

        $this->assertSame([], $unknown, "These ac- groups name no criterion:\n" . implode("\n", $unknown));
    }

    public function testACriterionWithATestIsNoGap(): void
    {
        $both = array_keys(array_intersect_key($this->gaps(), $this->acGroups()));

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

    /**
     * Every `ac-` group in the PHP files under `tests/`, with the `file:line` of each use.
     *
     * @return array<string, list<string>>
     */
    private function acGroups(): array
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
            preg_match_all(self::GROUP, $source, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[2] as [$id, $offset]) {
                $line = substr_count($source, "\n", 0, $offset) + 1;
                $groups[$id][] = substr($file->getPathname(), strlen($root) + 1) . ':' . $line;
            }
        }
        ksort($groups);

        return $groups;
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
