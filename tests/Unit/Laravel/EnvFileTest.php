<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Laravel;

use AgentCat\Laravel\Internal\EnvFile;
use AgentCat\Tests\Support\FileTree;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\TestCase;

final class EnvFileTest extends TestCase
{
    private string $dir = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = getenv('HOME') . '/envfile-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        chmod($this->dir, 0o700);
        FileTree::remove($this->dir);
    }

    public function testSetReplacesTheLineAndKeepsTheRest(): void
    {
        $path = $this->file("APP_NAME=Demo\nAGENTCAT_PROJECT_ID=old\nAPP_ENV=local\n");

        EnvFile::set($path, 'AGENTCAT_PROJECT_ID', 'proj_new');

        $this->assertSame("APP_NAME=Demo\nAGENTCAT_PROJECT_ID=proj_new\nAPP_ENV=local\n", file_get_contents($path));
    }

    public function testSetKeepsCrlfLineEndingsAndIgnoresCommentsAndLongerKeys(): void
    {
        $path = $this->file("#AGENTCAT_PROJECT_ID=c\r\nXAGENTCAT_PROJECT_ID=x\r\nAGENTCAT_PROJECT_ID=old\r\nAPP_ENV=local\r\n");

        EnvFile::set($path, 'AGENTCAT_PROJECT_ID', 'proj_new');

        $this->assertSame("#AGENTCAT_PROJECT_ID=c\r\nXAGENTCAT_PROJECT_ID=x\r\nAGENTCAT_PROJECT_ID=proj_new\r\nAPP_ENV=local\r\n", file_get_contents($path));
    }

    public function testSetReplacesOnlyTheFirstLineAndQuotesTheKey(): void
    {
        $path = $this->file("A.B=1\nAXB=2\nA.B=3\n");

        EnvFile::set($path, 'A.B', 'v');

        $this->assertSame("A.B=v\nAXB=2\nA.B=3\n", file_get_contents($path));
    }

    public function testSetReplacesAnExportedLineAndKeepsTheExport(): void
    {
        $path = $this->file("A=1\nexport  K=old\nB=2\n");

        EnvFile::set($path, 'K', 'v');
        EnvFile::ensure($path, 'K');

        $this->assertSame("A=1\nexport  K=v\nB=2\n", file_get_contents($path));
    }

    public function testSetRefusesAnUnreadableFileAndWritesNothing(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('File modes do not apply to root');
        }
        $path = $this->file("A=1\nK=old\n");
        chmod($path, 0o200);

        $e = Thrown::by(\RuntimeException::class, static fn() => EnvFile::set($path, 'K', 'new'));
        chmod($path, 0o600);

        $this->assertSame('Cannot read ' . $path, $e->getMessage());
        $this->assertSame("A=1\nK=old\n", file_get_contents($path));
    }

    public function testSetAppendsOnANewLine(): void
    {
        $path = $this->file('APP_NAME=Demo');

        EnvFile::set($path, 'AGENTCAT_PROJECT_ID', 'proj_new');

        $this->assertSame("APP_NAME=Demo\nAGENTCAT_PROJECT_ID=proj_new\n", file_get_contents($path));
    }

    public function testSetAppendsWithCrlfWhenTheFileUsesIt(): void
    {
        $path = $this->file("APP_NAME=Demo\r\nAPP_ENV=local");

        EnvFile::set($path, 'K', 'v');

        $this->assertSame("APP_NAME=Demo\r\nAPP_ENV=local\r\nK=v\r\n", file_get_contents($path));
    }

    public function testSetCreatesAMissingFile(): void
    {
        $path = $this->dir . '/.env';

        EnvFile::set($path, 'AGENTCAT_PROJECT_ID', 'proj_new');

        $this->assertSame("AGENTCAT_PROJECT_ID=proj_new\n", file_get_contents($path));
    }

    public function testSetWritesADollarOrBackslashValueLiterally(): void
    {
        $path = $this->file("K=old\n");

        EnvFile::set($path, 'K', 'a$1\1${0}');
        $this->assertSame("K=a\$1\\1\${0}\n", file_get_contents($path));
    }

    public function testSetLeavesTheFileAloneWhenTheLineIsAlreadyRight(): void
    {
        $path = $this->file("K=v\n");
        chmod($path, 0o400);

        EnvFile::set($path, 'K', 'v');

        $this->assertSame("K=v\n", file_get_contents($path));
    }

    public function testSetThrowsOnAnUnwritableFile(): void
    {
        $path = $this->file("K=old\n");
        chmod($path, 0o400);

        $e = Thrown::by(\RuntimeException::class, static fn() => EnvFile::set($path, 'K', 'new'));

        $this->assertSame('Cannot write ' . $path, $e->getMessage());
        $this->assertSame("K=old\n", file_get_contents($path));
    }

    public function testEnsureAddsAnEmptyKeyOnceAndNeverCreatesTheFile(): void
    {
        $path = $this->file("APP_NAME=Demo\n");

        EnvFile::ensure($path, 'AGENTCAT_PROJECT_ID');
        EnvFile::ensure($path, 'AGENTCAT_PROJECT_ID');
        EnvFile::ensure($path . '.missing', 'AGENTCAT_PROJECT_ID');

        $this->assertSame("APP_NAME=Demo\nAGENTCAT_PROJECT_ID=\n", file_get_contents($path));
        $this->assertFileDoesNotExist($path . '.missing');
    }

    private function file(string $contents): string
    {
        $path = $this->dir . '/.env' . count((array) scandir($this->dir));
        file_put_contents($path, $contents);

        return $path;
    }
}
