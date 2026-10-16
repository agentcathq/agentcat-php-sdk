<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Errors;

use AgentCat\Event;
use AgentCat\Internal\Errors\PathNormalizer;
use AgentCat\Internal\Errors\StackFrames;
use AgentCat\Tests\Fixtures\Errors\Thrower;
use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class StackFramesTest extends TestCase
{
    private const string FILE = '/srv/app/src/A.php';

    private const string WRAPPER = 'agentcat-test';

    private string $tempDir = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/agentcat-frames-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o700);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    private function frames(): StackFrames
    {
        return new StackFrames('/srv/app/vendor', '/srv/app/vendor/agentcat/sdk/src', false);
    }

    /** @param list<\stdClass> $frames */
    private function at(array $frames, int $index): \stdClass
    {
        return $frames[$index] ?? self::fail('no frame at index ' . $index);
    }

    /** @return array<mixed> */
    private function vars(\stdClass $frame): array
    {
        return get_object_vars($frame);
    }

    private function tempFile(string $name, string $contents): string
    {
        $path = $this->tempDir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    /** @return iterable<string, array{string, int|string}> */
    public static function constantProvider(): iterable
    {
        yield 'frame cap' => ['MAX_FRAMES', 50];
        yield 'internal filename' => ['INTERNAL_FILENAME', '[internal]'];
        yield 'main function' => ['MAIN_FUNCTION', '{main}'];
        yield 'anonymous function' => ['ANONYMOUS_FUNCTION', '<anonymous>'];
        yield 'source size cap' => ['MAX_SOURCE_BYTES', 1_048_576];
    }

    #[DataProvider('constantProvider')]
    public function testConstants(string $name, int|string $expected): void
    {
        $this->assertSame(StackFrames::{$name}, $expected);
    }

    /** @return iterable<string, array{string}> */
    public static function libraryPathProvider(): iterable
    {
        yield 'vendor dir' => ['/srv/app/vendor/acme/x.php'];
        yield 'other vendor dir' => ['/other/vendor/y.php'];
        yield 'windows vendor dir' => ['C:\app\vendor\z.php'];
        yield 'sdk source' => ['/srv/app/vendor/agentcat/sdk/src/Event.php'];
    }

    #[DataProvider('libraryPathProvider')]
    #[Group('ac-05-E13')]
    public function testLibraryPathsAreNotInApp(string $path): void
    {
        $this->assertFalse($this->frames()->isInApp($path));
    }

    /** @return iterable<string, array{string}> */
    public static function appPathProvider(): iterable
    {
        yield 'project source' => ['/srv/app/src/Tools.php'];
        yield 'phar' => ['phar:///x.phar/a.php'];
        yield 'eval' => ["/x/a.php(3) : eval()'d code"];
        yield 'vendor-like name' => ['/srv/app/vendors/a.php'];
    }

    #[DataProvider('appPathProvider')]
    #[Group('ac-05-E13')]
    public function testProjectPathsAreInApp(string $path): void
    {
        $this->assertTrue($this->frames()->isInApp($path));
    }

    #[Group('ac-05-E13')]
    public function testConfiguredVendorDirIsNotInApp(): void
    {
        $frames = new StackFrames('/custom-vendor-dir', '/nowhere', false);

        $this->assertFalse($frames->isInApp('/custom-vendor-dir/q.php'));
        $this->assertTrue($frames->isInApp('/custom-vendor-dirx/q.php'));
        $this->assertTrue($frames->isInApp('/srv/app/src/Tools.php'));
    }

    #[Group('ac-05-E13')]
    public function testSdkSourceDirIsNotInApp(): void
    {
        $frames = new StackFrames(null, '/work/sdk/src', false);

        $this->assertFalse($frames->isInApp('/work/sdk/src/Event.php'));
        $this->assertTrue($frames->isInApp('/work/sdk/srcx/a.php'));
        $this->assertTrue($frames->isInApp('/work/sdk/tests/a.php'));
    }

    #[Group('ac-05-E13')]
    public function testWindowsDirectoriesAreCompared(): void
    {
        $frames = new StackFrames('C:\app\deps', 'C:\sdk\src', false);

        $this->assertFalse($frames->isInApp('C:\app\deps\acme\a.php'));
        $this->assertFalse($frames->isInApp('C:\sdk\src\Event.php'));
        $this->assertTrue($frames->isInApp('C:\app\src\Tools.php'));
    }

    #[Group('ac-05-E13')]
    public function testDefaultUsesComposerVendorDirAndSdkSource(): void
    {
        $frames = StackFrames::default();

        $this->assertTrue($frames->isInApp(__FILE__));
        $this->assertFalse($frames->isInApp((string) (new \ReflectionClass(Event::class))->getFileName()));
        $this->assertFalse($frames->isInApp((string) (new \ReflectionClass(StackFrames::class))->getFileName()));
        $this->assertFalse($frames->isInApp((string) (new \ReflectionClass(ClassLoader::class))->getFileName()));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function functionNameProvider(): iterable
    {
        yield 'method' => [['class' => 'App\Tools', 'type' => '->', 'function' => 'weather'], 'weather'];
        yield 'static method' => [['class' => 'App\Tools', 'type' => '::', 'function' => 'make'], 'make'];
        yield 'global function' => [['function' => 'strlen'], 'strlen'];
        yield 'namespaced function' => [['function' => 'App\helper'], 'helper'];
        yield 'closure' => [['function' => '{closure}'], '{closure}'];
        yield 'namespaced closure' => [['function' => 'App\{closure}'], '{closure}'];
        yield 'php 8.4 closure' => [['function' => '{closure:/app/src/Tools.php:12}'], '{closure}'];
        yield 'php 8.4 closure in method' => [['class' => 'App\Tools', 'type' => '->', 'function' => '{closure:/app/src/Tools.php:12}'], '{closure}'];
        yield 'php 8.4 nested closure' => [['function' => 'App\{closure:{closure:/a.php:3}:4}'], '{closure}'];
        yield 'php 8.4 closure naming a method' => [['function' => 'App\{closure:App\Tools::weather():12}'], '{closure}'];
        yield 'php 8.4 closure with windows path' => [['function' => '{closure:C:\app\a.php:3}'], '{closure}'];
        yield 'empty' => [[], '<anonymous>'];
        yield 'empty name' => [['function' => ''], '<anonymous>'];
        yield 'non-string name' => [['class' => 'App\Tools', 'function' => 42], '<anonymous>'];
    }

    /** @param array<string, mixed> $entry */
    #[DataProvider('functionNameProvider')]
    #[Group('ac-05-C2')]
    public function testFunctionName(array $entry, string $expected): void
    {
        $this->assertSame($expected, StackFrames::functionName($entry));
    }

    /** @return iterable<string, array{array<string, mixed>, string|null}> */
    public static function moduleNameProvider(): iterable
    {
        yield 'method' => [['class' => 'App\Tools', 'type' => '->', 'function' => 'weather'], 'App\Tools'];
        yield 'static method' => [['class' => 'App\Tools', 'type' => '::', 'function' => 'make'], 'App\Tools'];
        yield 'closure in method' => [['class' => 'App\Tools', 'type' => '->', 'function' => 'App\{closure}'], 'App\Tools'];
        yield 'php 8.4 closure in method' => [['class' => 'App\Tools', 'type' => '->', 'function' => '{closure:App\Tools::weather():12}'], 'App\Tools'];
        yield 'namespaced function' => [['function' => 'App\helper'], 'App'];
        yield 'deep namespace function' => [['function' => 'App\Support\helper'], 'App\Support'];
        yield 'namespaced closure' => [['function' => 'App\{closure}'], 'App'];
        yield 'php 8.4 namespaced closure' => [['function' => 'App\{closure:{closure:/a.php:3}:4}'], 'App'];
        yield 'php 8.4 closure naming a method' => [['function' => 'App\{closure:App\Tools::weather():12}'], 'App'];
        yield 'global function' => [['function' => 'strlen'], null];
        yield 'global closure' => [['function' => '{closure}'], null];
        yield 'php 8.4 global closure with windows path' => [['function' => '{closure:C:\app\a.php:3}'], null];
        yield 'empty' => [[], null];
        yield 'empty class' => [['class' => '', 'function' => 'App\helper'], 'App'];
        yield 'non-string class' => [['class' => 7, 'function' => 'strlen'], null];
        yield 'non-string function' => [['function' => ['App\helper']], null];
        yield 'anonymous class' => [['class' => "class@anonymous\0/app/a.php:12\$0", 'type' => '->', 'function' => 'run'], 'class@anonymous'];
        yield 'anonymous subclass' => [['class' => "App\\Base@anonymous\0/app/b.php:3\$1", 'type' => '::', 'function' => 'make'], 'App\Base@anonymous'];
    }

    /** @param array<string, mixed> $entry */
    #[DataProvider('moduleNameProvider')]
    #[Group('ac-05-C2')]
    public function testModuleName(array $entry, ?string $expected): void
    {
        $this->assertSame($expected, StackFrames::moduleName($entry));
    }

    #[Group('ac-05-E11')]
    public function testFramesShiftCallerLocationsOntoCalledFunctions(): void
    {
        $frames = $this->frames()->fromTrace(self::FILE, 9, [
            ['file' => self::FILE, 'line' => 3, 'function' => 'a'],
            ['file' => self::FILE, 'line' => 4, 'function' => 'b'],
        ]);

        $this->assertCount(3, $frames);
        $expected = [['a', 9], ['b', 3], ['{main}', 4]];
        foreach ($frames as $index => $frame) {
            $this->assertSame(
                [
                    'function' => $expected[$index][0],
                    'filename' => 'src/A.php',
                    'abs_path' => self::FILE,
                    'lineno' => $expected[$index][1],
                    'in_app' => true,
                ],
                $this->vars($frame),
            );
        }
    }

    public function testEmptyTraceGivesOneMainFrameAtTheThrowSite(): void
    {
        $frames = $this->frames()->fromTrace(self::FILE, 9, []);

        $this->assertCount(1, $frames);
        $this->assertSame(
            ['function' => '{main}', 'filename' => 'src/A.php', 'abs_path' => self::FILE, 'lineno' => 9, 'in_app' => true],
            $this->vars($this->at($frames, 0)),
        );
    }

    #[Group('ac-05-E13')]
    public function testEntryWithoutFileGivesAnInternalFrame(): void
    {
        $frames = $this->frames()->fromTrace(self::FILE, 9, [
            ['function' => '{closure}'],
            ['file' => self::FILE, 'line' => 16, 'function' => 'array_map'],
        ]);

        $this->assertCount(3, $frames);
        $this->assertSame('{closure}', $this->at($frames, 0)->function);
        $this->assertSame(9, $this->at($frames, 0)->lineno);
        $this->assertSame(['function' => 'array_map', 'filename' => '[internal]', 'in_app' => false], $this->vars($this->at($frames, 1)));
        $this->assertSame(
            ['function' => '{main}', 'filename' => 'src/A.php', 'abs_path' => self::FILE, 'lineno' => 16, 'in_app' => true],
            $this->vars($this->at($frames, 2)),
        );
    }

    #[Group('ac-05-C2')]
    public function testModuleFollowsFunctionInFrames(): void
    {
        $frames = $this->frames()->fromTrace(self::FILE, 9, [
            ['file' => self::FILE, 'line' => 3, 'class' => 'App\Tools', 'type' => '->', 'function' => 'weather'],
            ['class' => 'App\Tools', 'type' => '->', 'function' => 'App\{closure}'],
            ['file' => '/srv/app/vendor/acme/x.php', 'line' => 5, 'function' => 'App\helper'],
        ]);

        $this->assertCount(4, $frames);
        $this->assertSame(
            ['function' => 'weather', 'module' => 'App\Tools', 'filename' => 'src/A.php', 'abs_path' => self::FILE, 'lineno' => 9, 'in_app' => true],
            $this->vars($this->at($frames, 0)),
        );
        $this->assertSame(
            ['function' => '{closure}', 'module' => 'App\Tools', 'filename' => 'src/A.php', 'abs_path' => self::FILE, 'lineno' => 3, 'in_app' => true],
            $this->vars($this->at($frames, 1)),
        );
        $this->assertSame(['function' => 'helper', 'module' => 'App', 'filename' => '[internal]', 'in_app' => false], $this->vars($this->at($frames, 2)));
        $this->assertSame(
            ['function' => '{main}', 'filename' => 'vendor/acme/x.php', 'abs_path' => '/srv/app/vendor/acme/x.php', 'lineno' => 5, 'in_app' => false],
            $this->vars($this->at($frames, 3)),
        );
    }

    /** @return iterable<string, array{list<array<string, mixed>>}> */
    public static function malformedTraceProvider(): iterable
    {
        yield 'missing everything' => [[[]]];
        yield 'missing file' => [[['line' => 3, 'function' => 'a']]];
        yield 'missing line' => [[['file' => self::FILE, 'function' => 'a']]];
        yield 'missing function' => [[['file' => self::FILE, 'line' => 3, 'class' => 'App\A', 'type' => '->']]];
        yield 'missing class' => [[['file' => self::FILE, 'line' => 3, 'type' => '->', 'function' => 'a']]];
        yield 'non-string values' => [[['file' => 12, 'line' => '3', 'class' => ['x'], 'type' => 5, 'function' => null]]];
        yield 'object values' => [[['file' => new \stdClass(), 'line' => 3.5, 'class' => new \stdClass(), 'function' => new \stdClass()]]];
    }

    /** @param list<array<string, mixed>> $trace */
    #[DataProvider('malformedTraceProvider')]
    #[Group('ac-05-E17')]
    #[Group('ac-05-C1')]
    public function testMalformedEntriesStillGiveStringFilenameAndFunction(array $trace): void
    {
        $frames = $this->frames()->fromTrace(self::FILE, 9, $trace);

        $this->assertCount(2, $frames);
        foreach ($frames as $frame) {
            $this->assertIsString($frame->filename);
            $this->assertIsString($frame->function);
            $this->assertIsBool($frame->in_app);
        }
    }

    #[Group('ac-05-E17')]
    public function testFileWithoutLineHasNoLineno(): void
    {
        $frames = $this->frames()->fromTrace(self::FILE, 9, [['file' => self::FILE, 'line' => '3', 'function' => 'a']]);

        $this->assertSame(['function' => '{main}', 'filename' => 'src/A.php', 'abs_path' => self::FILE, 'in_app' => true], $this->vars($this->at($frames, 1)));
    }

    /** @return list<array<string, mixed>> */
    private function syntheticTrace(int $entries): array
    {
        $trace = [];
        for ($index = 0; $index < $entries; ++$index) {
            $trace[] = ['file' => self::FILE, 'line' => 100 + $index, 'function' => 'f' . $index];
        }

        return $trace;
    }

    #[Group('ac-05-E12')]
    public function testTraceIsCappedAtFiftyFramesKeepingTheThrowSite(): void
    {
        $frames = $this->frames()->fromTrace(self::FILE, 9, $this->syntheticTrace(70));

        $this->assertCount(50, $frames);
        $this->assertSame('f0', $this->at($frames, 0)->function);
        $this->assertSame(9, $this->at($frames, 0)->lineno);
        $this->assertSame('f49', $this->at($frames, 49)->function);
    }

    #[Group('ac-05-E12')]
    public function testCapBoundary(): void
    {
        $exactly = $this->frames()->fromTrace(self::FILE, 9, $this->syntheticTrace(50));
        $this->assertCount(50, $exactly);
        $this->assertSame('f49', $this->at($exactly, 49)->function);

        $oneShort = $this->frames()->fromTrace(self::FILE, 9, $this->syntheticTrace(49));
        $this->assertCount(50, $oneShort);
        $this->assertSame('{main}', $this->at($oneShort, 49)->function);
        $this->assertSame(148, $this->at($oneShort, 49)->lineno);
    }

    #[Group('ac-05-E11')]
    public function testFrameShape(): void
    {
        $frames = $this->frames()->fromTrace(self::FILE, 9, [
            ['file' => '/srv/app/vendor/acme/x.php', 'line' => 3, 'function' => 'a'],
            ['function' => 'b'],
        ]);

        foreach ($frames as $frame) {
            $this->assertObjectNotHasProperty('colno', $frame);
            $this->assertIsBool($frame->in_app);
        }
        $this->assertSame(9, $this->at($frames, 0)->lineno);
        $this->assertSame(self::FILE, $this->at($frames, 0)->abs_path);
        $this->assertSame('/srv/app/vendor/acme/x.php', $this->at($frames, 1)->abs_path);
        $this->assertSame('vendor/acme/x.php', $this->at($frames, 1)->filename);
        $this->assertFalse($this->at($frames, 1)->in_app);
    }

    #[Group('ac-05-E14')]
    public function testContextLineIsTheRawSourceLineOfInAppFrames(): void
    {
        $path = $this->tempFile('a.php', "<?php\n\$a = 1;\n\$b = 2;\r\n");

        $frames = $this->frames()->fromTrace($path, 2, [
            ['file' => $path, 'line' => 3, 'function' => 'a'],
            ['file' => $path, 'line' => 4, 'function' => 'b'],
            ['file' => $path, 'line' => 0, 'function' => 'c'],
        ]);

        $this->assertSame('$a = 1;', $this->at($frames, 0)->context_line);
        $this->assertSame("\$b = 2;\r", $this->at($frames, 1)->context_line);
        $this->assertSame('', $this->at($frames, 2)->context_line);
        $this->assertObjectNotHasProperty('context_line', $this->at($frames, 3));
        $this->assertSame(
            ['function' => 'a', 'filename' => PathNormalizer::relative($path, false), 'abs_path' => $path, 'lineno' => 2, 'in_app' => true, 'context_line' => '$a = 1;'],
            $this->vars($this->at($frames, 0)),
        );
    }

    #[Group('ac-05-E14')]
    public function testOutOfRangeLineHasNoContextLine(): void
    {
        $path = $this->tempFile('b.php', "<?php\n");

        $frames = $this->frames()->fromTrace($path, 5, []);

        $this->assertObjectNotHasProperty('context_line', $this->at($frames, 0));
        $this->assertSame(5, $this->at($frames, 0)->lineno);

        $justPastTheEnd = $this->frames()->fromTrace($path, 3, []);
        $this->assertObjectNotHasProperty('context_line', $this->at($justPastTheEnd, 0));
    }

    #[Group('ac-05-E14')]
    public function testCrlfFileKeepsCarriageReturns(): void
    {
        $path = $this->tempFile('crlf.php', "a\r\nb\r\n");

        $this->assertSame(["a\r", "b\r", ''], StackFrames::readLines($path));
    }

    public function testFileAtTheSizeLimitIsRead(): void
    {
        $path = $this->tempFile('limit.php', str_repeat('x', StackFrames::MAX_SOURCE_BYTES - 1) . "\n");

        $lines = StackFrames::readLines($path);

        $this->assertIsArray($lines);
        $this->assertCount(2, $lines);
    }

    /** @return list<string> */
    private function unreadablePaths(): array
    {
        return [
            $this->tempDir . '/missing.php',
            $this->tempDir,
            $this->tempDir . "/a\0b.php",
            "/x/a.php(3) : eval()'d code",
            $this->tempFile('large.php', str_repeat('x', StackFrames::MAX_SOURCE_BYTES + 1)),
            self::WRAPPER . '://throw.php',
            self::WRAPPER . '://unopenable.php',
        ];
    }

    #[Group('ac-05-E14')]
    public function testUnreadableSourcesGiveNullWithoutAWarning(): void
    {
        $this->registerTestWrapper();
        error_clear_last();

        try {
            foreach ($this->unreadablePaths() as $path) {
                $this->assertNull(StackFrames::readLines($path), str_replace("\0", '\0', $path));
            }
        } finally {
            stream_wrapper_unregister(self::WRAPPER);
        }
        $this->assertNull(error_get_last());
    }

    #[Group('review-focus-2')]
    public function testThrowingErrorHandlerSeesNoWarningAndIsRestored(): void
    {
        $this->registerTestWrapper();
        set_error_handler(static fn(int $no, string $str): never => throw new \ErrorException($str, 0, $no));

        try {
            foreach ($this->unreadablePaths() as $path) {
                $this->assertNull(StackFrames::readLines($path), str_replace("\0", '\0', $path));
            }

            try {
                trigger_error('x', E_USER_WARNING);
                self::fail('the test error handler is no longer installed');
            } catch (\ErrorException $exception) {
                $this->assertSame('x', $exception->getMessage());
            }
        } finally {
            restore_error_handler();
            stream_wrapper_unregister(self::WRAPPER);
        }
    }

    /** A stream wrapper whose `stat` throws for `throw` paths and whose files stat as readable but never open. */
    private function registerTestWrapper(): void
    {
        $wrapper = new class {
            public mixed $context = null;

            /** @return array<string, int> */
            public function url_stat(string $path, int $flags): array
            {
                if (str_contains($path, 'throw')) {
                    throw new \RuntimeException('stat failed for ' . $path . ' with flags ' . $flags);
                }

                return ['mode' => 0o100644, 'size' => 10];
            }

            public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
            {
                return $path === '' && $mode === '' && $options === 0 && $openedPath === null;
            }
        };
        stream_wrapper_register(self::WRAPPER, $wrapper::class);
    }

    public function testStackStringMatchesPhpTraceWithoutArguments(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '1');

        try {
            Thrower::viaStatic('boom');
        } catch (\RuntimeException $exception) {
            $file = (string) (new \ReflectionClass(Thrower::class))->getFileName();
            $stack = StackFrames::stackString($exception);

            $this->assertStringStartsWith(
                "RuntimeException: boom in {$file}:14\nStack trace:\n#0 {$file}(" . Thrower::$callLine . '): AgentCat\Tests\Fixtures\Errors\Thrower->fail()',
                $stack,
            );
            // Same lines as PHP's own trace, minus the argument list PHP keeps for include/require even then.
            $ours = array_slice(explode("\n", $stack), 2);
            $php = explode("\n", $exception->getTraceAsString());
            $this->assertCount(count($php), $ours);
            foreach ($php as $index => $line) {
                $ourLine = substr($ours[$index] ?? '', 0, -1);
                $this->assertSame($ourLine, substr($line, 0, strlen($ourLine)));
            }
            $this->assertStringEndsWith('{main}', $stack);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    public function testStackStringNeverContainsArguments(): void
    {
        $previousIgnore = ini_set('zend.exception_ignore_args', '0');
        $previousLength = ini_set('zend.exception_string_param_max_len', '15');

        try {
            Thrower::viaStatic('boom');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString("'boom'", $exception->getTraceAsString());
            $stack = StackFrames::stackString($exception);

            $this->assertSame(1, substr_count($stack, 'boom'));
            $lines = explode("\n", $stack);
            foreach (array_slice($lines, 2, -1) as $line) {
                $this->assertStringEndsWith('()', $line);
            }
            $this->assertSame('#' . (count($lines) - 3) . ' {main}', end($lines));
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previousIgnore);
            ini_set('zend.exception_string_param_max_len', (string) $previousLength);
        }
    }

    public function testStackStringCutsAnonymousClassNames(): void
    {
        $counter = new class implements \Countable {
            #[\Override]
            public function count(): int
            {
                throw new class ('anonymous boom') extends \RuntimeException {};
            }
        };

        try {
            $counter->count();
        } catch (\RuntimeException $exception) {
            $stack = StackFrames::stackString($exception);

            $this->assertStringStartsWith('RuntimeException@anonymous: anonymous boom in ' . __FILE__ . ':', $stack);
            $this->assertStringContainsString("\n#0 " . __FILE__ . '(', $stack);
            $this->assertStringContainsString('): Countable@anonymous->count()' . "\n#1 ", $stack);
            $this->assertStringNotContainsString("\0", $stack);
        }
    }

    public function testStackStringMarksInternalFunctions(): void
    {
        try {
            array_map(static fn(int $value): never => throw new \LogicException('in callback ' . $value), [1]);
        } catch (\LogicException $exception) {
            $stack = StackFrames::stackString($exception);

            // PHP 8.4+ names closures `{closure:…}`; the stack string keeps the raw name.
            $this->assertMatchesRegularExpression(
                '/\n#0 \[internal function\]: ' . preg_quote(self::class, '/') . '::\S*\{closure\S*\(\)\n#1 ' . preg_quote(__FILE__, '/') . '\(\d+\): array_map\(\)\n/',
                $stack,
            );
        }
    }
}
