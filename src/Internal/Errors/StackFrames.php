<?php

declare(strict_types=1);

namespace AgentCat\Internal\Errors;

use AgentCat\Internal\Support\ClassName;
use AgentCat\Internal\Support\ErrorText;
use Composer\Autoload\ClassLoader;

/**
 * Builds AgentCat stack frames (newest first) from a PHP trace.
 *
 * A PHP trace entry names the called function but carries the caller's file and line, so each frame takes the
 * function of entry `k` and the location of entry `k - 1` (the throw site for frame 0). `function` is the bare
 * function or method name and `module` is the class or namespace: the server groups non-JavaScript platforms on
 * `module|function`, so neither may hold a file path or line.
 *
 * @internal
 */
final readonly class StackFrames
{
    public const int MAX_FRAMES = 50;
    public const string INTERNAL_FILENAME = '[internal]';
    public const string MAIN_FUNCTION = '{main}';
    public const string ANONYMOUS_FUNCTION = '<anonymous>';
    public const int MAX_SOURCE_BYTES = 1_048_576;

    private const string CLOSURE = '{closure}';

    private ?string $vendorDir;

    private string $sdkSourceDir;

    /**
     * @param string|null $vendorDir Composer vendor directory; its files are library code.
     * @param string $sdkSourceDir This package's `src` directory; its files are library code.
     * @param string|false $cwd Working directory stripped from file names (`getcwd()` result).
     */
    public function __construct(?string $vendorDir, string $sdkSourceDir, private string|false $cwd)
    {
        $this->vendorDir = $vendorDir === null ? null : str_replace('\\', '/', $vendorDir);
        $this->sdkSourceDir = str_replace('\\', '/', $sdkSourceDir);
    }

    public static function default(): self
    {
        $loader = (new \ReflectionClass(ClassLoader::class))->getFileName();

        return new self($loader === false ? null : dirname($loader, 2), dirname(__DIR__, 2), getcwd());
    }

    /** @return list<\stdClass> */
    public function build(\Throwable $error): array
    {
        return $this->fromTrace($error->getFile(), $error->getLine(), $error->getTrace());
    }

    /**
     * @param list<array<string, mixed>> $trace `Throwable::getTrace()` entries, newest first.
     *
     * @return list<\stdClass>
     */
    public function fromTrace(string $file, int $line, array $trace): array
    {
        $frames = [];
        $sources = [];
        $location = [$file, $line];
        foreach ($trace as $entry) {
            $frames[] = $this->frame(self::functionName($entry), self::moduleName($entry), $location, $sources);
            if (count($frames) === self::MAX_FRAMES) {
                return $frames;
            }
            $entryFile = $entry['file'] ?? null;
            $entryLine = $entry['line'] ?? null;
            $location = [is_string($entryFile) ? $entryFile : null, is_int($entryLine) ? $entryLine : null];
        }
        $frames[] = $this->frame(self::MAIN_FUNCTION, null, $location, $sources);

        return $frames;
    }

    public function isInApp(string $absPath): bool
    {
        $path = str_replace('\\', '/', $absPath);
        if (str_contains($path, '/vendor/')) {
            return false;
        }
        if ($this->vendorDir !== null && str_starts_with($path, $this->vendorDir . '/')) {
            return false;
        }

        return !str_starts_with($path, $this->sdkSourceDir . '/');
    }

    /**
     * The bare function or method name; closures are `{closure}` on every PHP version.
     *
     * @param array<string, mixed> $entry
     */
    public static function functionName(array $entry): string
    {
        $name = self::splitFunction($entry)[1];

        return $name === '' ? self::ANONYMOUS_FUNCTION : $name;
    }

    /**
     * The class of a method or closure frame (an anonymous class cut at its NUL byte), else the namespace of a named
     * function. Null for global functions and for closures without a class: PHP 8.3 prefixes a closure name with its
     * namespace and PHP 8.4+ does not, so a namespace module would split one throw site across PHP versions.
     *
     * @param array<string, mixed> $entry
     */
    public static function moduleName(array $entry): ?string
    {
        $class = $entry['class'] ?? null;
        if (is_string($class) && $class !== '') {
            return ClassName::of($class);
        }
        [$namespace, $name] = self::splitFunction($entry);

        return $namespace === '' || $name === self::CLOSURE ? null : $namespace;
    }

    /**
     * The `stack` string: the header and one line per trace entry, like `getTraceAsString()` but never with
     * argument values (they can hold personal data when `zend.exception_ignore_args` is off).
     */
    public static function stackString(\Throwable $error): string
    {
        $lines = [];
        foreach ($error->getTrace() as $index => $entry) {
            $location = isset($entry['file'], $entry['line']) ? $entry['file'] . '(' . $entry['line'] . ')' : '[internal function]';
            $lines[] = '#' . $index . ' ' . $location . ': ' . ClassName::of($entry['class'] ?? '') . ($entry['type'] ?? '') . ClassName::inFunction($entry['function']) . '()';
        }
        $lines[] = '#' . count($lines) . ' ' . self::MAIN_FUNCTION;

        return sprintf("%s: %s in %s:%d\nStack trace:\n", ClassName::of($error::class), ErrorText::message($error), $error->getFile(), $error->getLine())
            . implode("\n", $lines);
    }

    /**
     * The lines of a source file split on `"\n"` (a CRLF file keeps its `"\r"`), or null when the file cannot be
     * read or is larger than `MAX_SOURCE_BYTES`. Never throws and never lets a warning reach an error handler.
     *
     * @return list<string>|null
     */
    public static function readLines(string $file): ?array
    {
        set_error_handler(static fn(): bool => true);

        try {
            if (!is_file($file) || !is_readable($file) || filesize($file) > self::MAX_SOURCE_BYTES) {
                return null;
            }
            $contents = file_get_contents($file);

            return is_string($contents) ? explode("\n", $contents) : null;
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array{string, string} The namespace (empty for global names) and the bare name.
     */
    private static function splitFunction(array $entry): array
    {
        $function = $entry['function'] ?? null;
        // PHP 8.4 closure names embed the defining file or function and line: `{closure:/app/src/Tools.php:12}`.
        $name = is_string($function) ? preg_replace('/\{closure:.*\}/s', self::CLOSURE, $function) ?? $function : '';
        $separator = strrpos($name, '\\');

        return $separator === false ? ['', $name] : [substr($name, 0, $separator), substr($name, $separator + 1)];
    }

    /**
     * @param array{string|null, int|null} $location
     * @param array<string, list<string>|null> $sources Source lines read during this trace, by path.
     */
    private function frame(string $function, ?string $module, array $location, array &$sources): \stdClass
    {
        [$file, $line] = $location;
        $frame = new \stdClass();
        $frame->function = $function;
        if ($module !== null) {
            $frame->module = $module;
        }
        if ($file === null) {
            $frame->filename = self::INTERNAL_FILENAME;
            $frame->in_app = false;

            return $frame;
        }
        $frame->filename = PathNormalizer::relative($file, $this->cwd);
        $frame->abs_path = $file;
        if ($line !== null) {
            $frame->lineno = $line;
        }
        $inApp = $this->isInApp($file);
        $frame->in_app = $inApp;
        if ($inApp && $line !== null) {
            if (!array_key_exists($file, $sources)) {
                $sources[$file] = self::readLines($file);
            }
            $contextLine = $sources[$file][$line - 1] ?? null;
            if ($contextLine !== null) {
                $frame->context_line = $contextLine;
            }
        }

        return $frame;
    }
}
