<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Guards `tests/bootstrap.php`: every test process gets its own HOME in the git-ignored `.cache/` of the repo. */
final class TestHomeTest extends TestCase
{
    public function testHomeIsAPerProcessDirectoryUnderTheRepoCache(): void
    {
        $home = getenv('HOME');

        $this->assertIsString($home);
        $this->assertMatchesRegularExpression('#\A' . preg_quote(dirname(__DIR__, 2), '#') . '/\.cache/test-home/[0-9a-f]{12}\z#', $home);
        $this->assertDirectoryExists($home);
        $this->assertSame($home, $_SERVER['HOME'] ?? null);
        $this->assertSame($home, $_ENV['HOME'] ?? null);
    }
}
