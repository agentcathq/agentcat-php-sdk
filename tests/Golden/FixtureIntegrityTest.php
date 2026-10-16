<?php

declare(strict_types=1);

namespace AgentCat\Tests\Golden;

use AgentCat\Tests\Support\Golden;
use PHPUnit\Framework\TestCase;

final class FixtureIntegrityTest extends TestCase
{
    public function testEveryFixtureMatchesRecordedChecksum(): void
    {
        $rows = 0;
        foreach (explode("\n", Golden::text('SOURCE.md')) as $line) {
            if (preg_match('/^([0-9a-f]{64})  (\S+)$/', $line, $match) !== 1) {
                continue;
            }
            ++$rows;
            $this->assertSame($match[1], hash('sha256', Golden::text($match[2])), $match[2]);
        }

        $this->assertSame(6, $rows);
    }

    public function testEveryWireFixtureMatchesRecordedChecksum(): void
    {
        $rows = 0;
        foreach (explode("\n", Golden::text('wire/SOURCE.md')) as $line) {
            if (preg_match('/^([0-9a-f]{64})  (\S+)$/', $line, $match) !== 1) {
                continue;
            }
            ++$rows;
            $this->assertSame($match[1], hash('sha256', Golden::text($match[2])), $match[2]);
        }

        $this->assertSame(4, $rows);
    }
}
