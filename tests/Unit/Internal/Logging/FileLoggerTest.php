<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Logging;

use AgentCat\Internal\Logging\FileLogger;
use AgentCat\Internal\Logging\LogLine;
use AgentCat\Internal\SdkVersion;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class FileLoggerTest extends TestCase
{
    private string $path = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/agentcat-test-' . bin2hex(random_bytes(6)) . '.log';
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    #[Group('ac-03-35')]
    public function testWritesOneFormattedLinePerCall(): void
    {
        $logger = new FileLogger($this->path);

        $logger->info('first');
        $logger->error('second');

        $lines = file($this->path, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $this->assertCount(2, $lines);
        $prefix = '/^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\] \[sdk=[^ ]+ php=[^ ]+ mcp=(v?\d[^\] ]*|dev-[^\] ]+|unknown)(?: laravel-mcp=(v?\d[^\] ]*|dev-[^\] ]+))?\] /';
        $mcp = SdkVersion::installed('mcp/sdk');
        $this->assertNotNull($mcp, 'mcp/sdk is a dev dependency');
        $versions = sprintf('[%s]', LogLine::versions(SdkVersion::get(), PHP_VERSION, $mcp, SdkVersion::installed('laravel/mcp')));
        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression($prefix, $line);
        }
        $this->assertStringEndsWith('Z] ' . $versions . ' first', $lines[0]);
        $this->assertStringEndsWith('Z] ' . $versions . ' second', $lines[1]);
        $this->assertStringEndsWith("\n", (string) file_get_contents($this->path));
    }

    public function testUnwritablePathNeitherThrowsNorWarns(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ((error_reporting() & $errno) === 0) {
                return false; // suppressed with @
            }
            $warnings[] = $errstr;

            return true;
        });

        try {
            (new FileLogger('/nonexistent-dir/x.log'))->info('hello');
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
    }

    public function testResolveHomePrefersEnvironment(): void
    {
        $this->assertSame('/h', FileLogger::resolveHome('/h', static fn(): string => '/p'));
        $this->assertSame('/p', FileLogger::resolveHome('', static fn(): string => '/p'));
        $this->assertSame('/p', FileLogger::resolveHome(false, static fn(): string => '/p'));
        $this->assertNull(FileLogger::resolveHome(false, static fn(): ?string => null));
    }

    public function testPosixHomeMatchesPasswdEntry(): void
    {
        if (!function_exists('posix_getpwuid')) {
            self::markTestSkipped('ext-posix not loaded');
        }
        $entry = posix_getpwuid(posix_geteuid());
        $this->assertIsArray($entry);
        $this->assertSame($entry['dir'], FileLogger::posixHome());
    }

    public function testNoHomeMakesLoggingANoOp(): void
    {
        $home = sys_get_temp_dir() . '/agentcat-nohome-' . bin2hex(random_bytes(6));
        mkdir($home);

        try {
            (new FileLogger(null, static fn(): ?string => null))->info('hello');
            $this->assertSame([], glob($home . '/*'));
        } finally {
            rmdir($home);
        }
    }

    public function testHomeLookupResultDecidesPath(): void
    {
        $home = sys_get_temp_dir() . '/agentcat-lookup-' . bin2hex(random_bytes(6));
        mkdir($home);

        try {
            (new FileLogger(null, static fn(): string => $home))->info('hello');
            $this->assertFileExists($home . '/agentcat.log');
        } finally {
            @unlink($home . '/agentcat.log');
            rmdir($home);
        }
    }

    public function testDefaultPathLivesInHomeDirectory(): void
    {
        $home = sys_get_temp_dir() . '/agentcat-home-' . bin2hex(random_bytes(6));
        mkdir($home);
        $previous = getenv('HOME');
        putenv('HOME=' . $home);

        try {
            (new FileLogger())->info('hello');
            $this->assertFileExists($home . '/agentcat.log');
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
            @unlink($home . '/agentcat.log');
            rmdir($home);
        }
    }
}
