<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Diagnostics;

use AgentCat\Internal\Diagnostics\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class SeverityTest extends TestCase
{
    /** @return iterable<string, array{string, array{0: int, 1: string}}> */
    public static function lines(): iterable
    {
        yield 'fail' => ['Failed to send event', [17, 'ERROR']];
        yield 'error' => ['Some error happened', [17, 'ERROR']];
        yield 'warning and fail' => ['Warning: Failed to track', [17, 'ERROR']];
        yield 'FAIL in capitals' => ['IT FAILED', [17, 'ERROR']];
        yield 'warning' => ['Warning: x', [13, 'WARN']];
        yield 'warning inside' => ['[x] Warning: y', [13, 'WARN']];
        yield 'lower-case warning' => ['warning: x', [9, 'INFO']];
        yield 'empty' => ['', [9, 'INFO']];
        yield 'WARN prefix' => ['WARN: x', [9, 'INFO']];
        yield 'ERROR prefix' => ['ERROR: x', [17, 'ERROR']];
        yield 'invalid UTF-8' => ["\xFF error", [17, 'ERROR']];
    }

    /** @param array{0: int, 1: string} $expected */
    #[DataProvider('lines')]
    #[Group('ac-06-57')]
    public function testTheSeverityComesFromTheWholeLine(string $line, array $expected): void
    {
        $this->assertSame($expected, Severity::of($line));
    }
}
