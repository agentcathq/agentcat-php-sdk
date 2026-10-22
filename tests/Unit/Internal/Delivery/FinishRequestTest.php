<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Delivery;

use AgentCat\Internal\Delivery\FinishRequest;
use AgentCat\Tests\Support\Environment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FinishRequestTest extends TestCase
{
    /** @var list<int> `ignore_user_abort()` as seen by each probe call */
    private static array $probeCalls = [];

    #[\Override]
    protected function setUp(): void
    {
        self::$probeCalls = [];
    }

    /**
     * @return iterable<string, array{string, array<string, string>, list<string>, string|null}>
     */
    public static function runtimes(): iterable
    {
        yield 'PHP-FPM' => ['fpm-fcgi', [], ['fastcgi_finish_request'], 'fastcgi_finish_request'];
        yield 'PHP-FPM without the function' => ['fpm-fcgi', [], [], null];
        yield 'LiteSpeed' => ['litespeed', [], ['litespeed_finish_request'], 'litespeed_finish_request'];
        yield 'LiteSpeed without the function' => ['litespeed', [], [], null];
        yield 'FrankenPHP' => ['frankenphp', [], ['frankenphp_finish_request', 'fastcgi_finish_request'], 'frankenphp_finish_request'];
        yield 'FrankenPHP alias only' => ['frankenphp', [], ['fastcgi_finish_request'], 'fastcgi_finish_request'];
        yield 'FrankenPHP without the functions' => ['frankenphp', [], [], null];
        yield 'FrankenPHP worker mode' => ['frankenphp', ['FRANKENPHP_WORKER' => '1'], ['frankenphp_finish_request', 'fastcgi_finish_request'], null];
        yield 'CLI' => ['cli', [], ['fastcgi_finish_request', 'litespeed_finish_request', 'frankenphp_finish_request'], null];
        yield 'built-in web server' => ['cli-server', [], ['fastcgi_finish_request'], null];
        yield 'Apache mod_php' => ['apache2handler', [], ['fastcgi_finish_request'], null];
    }

    /**
     * @param array<string, string> $server
     * @param list<string>          $available
     */
    #[DataProvider('runtimes')]
    public function testFunctionFor(string $sapi, array $server, array $available, ?string $expected): void
    {
        $functionExists = static fn(string $name): bool => in_array($name, $available, true);

        $this->assertSame($expected, FinishRequest::functionFor($sapi, $server, $functionExists));
    }

    public function testFrankenPhpWorkerModeIsAlsoReadFromTheEnvironment(): void
    {
        $exists = static fn(string $name): bool => true;
        $getenv = $this->environment('1');

        $this->assertNull(FinishRequest::functionFor('frankenphp', [], $exists, $getenv));
        $this->assertSame('frankenphp_finish_request', FinishRequest::functionFor('frankenphp', [], $exists, $this->environment(false)));
        $this->assertNull(FinishRequest::functionFor('frankenphp', ['FRANKENPHP_WORKER' => '1'], $exists, $this->environment(false)));
        $this->assertTrue(FinishRequest::isFrankenPhpWorker([], $getenv));
        $this->assertFalse(FinishRequest::isFrankenPhpWorker([], $this->environment(false)));
    }

    /** An empty `FRANKENPHP_WORKER` still marks worker mode: only an unset variable is classic mode. */
    public function testAnEmptyWorkerVariableCounts(): void
    {
        $this->assertTrue(FinishRequest::isFrankenPhpWorker([], $this->environment('')));
        $this->assertTrue(FinishRequest::isFrankenPhpWorker(['FRANKENPHP_WORKER' => ''], $this->environment(false)));
    }

    public function testWorkerModeReadsTheRealEnvironmentByDefault(): void
    {
        $environment = Environment::clear('FRANKENPHP_WORKER');

        try {
            $this->assertFalse(FinishRequest::isFrankenPhpWorker([]));
            putenv('FRANKENPHP_WORKER=1');
            $this->assertTrue(FinishRequest::isFrankenPhpWorker([]));
            $this->assertNull(FinishRequest::functionFor('frankenphp', [], static fn(string $name): bool => true));
        } finally {
            $environment->restore();
        }
    }

    /** @return iterable<string, array{string, array<string, string>, bool}> */
    public static function processes(): iterable
    {
        yield 'Octane on Swoole or RoadRunner, a stdio server' => ['cli', [], true];
        yield 'phpdbg' => ['phpdbg', [], true];
        yield 'FrankenPHP worker mode' => ['frankenphp', ['FRANKENPHP_WORKER' => '1'], true];
        yield 'FrankenPHP classic mode' => ['frankenphp', [], false];
        yield 'PHP-FPM' => ['fpm-fcgi', [], false];
        yield 'PHP-FPM with a stray worker variable' => ['fpm-fcgi', ['FRANKENPHP_WORKER' => '1'], false];
        yield 'Apache mod_php' => ['apache2handler', [], false];
        yield 'built-in web server' => ['cli-server', [], false];
    }

    /** @param array<string, string> $server */
    #[DataProvider('processes')]
    public function testOutlivesRequest(string $sapi, array $server, bool $expected): void
    {
        $this->assertSame($expected, FinishRequest::outlivesRequest($sapi, $server, $this->environment(false)));
    }

    /** @return iterable<string, array{string, array<string, string>, list<string>, bool}> */
    public static function requestEnds(): iterable
    {
        yield 'Apache mod_php' => ['apache2handler', [], [], true];
        yield 'php-cgi' => ['cgi-fcgi', [], [], true];
        yield 'built-in web server' => ['cli-server', [], ['fastcgi_finish_request'], true];
        yield 'PHP-FPM' => ['fpm-fcgi', [], ['fastcgi_finish_request'], false];
        yield 'PHP-FPM with the function disabled' => ['fpm-fcgi', [], [], true];
        yield 'LiteSpeed' => ['litespeed', [], ['litespeed_finish_request'], false];
        yield 'FrankenPHP classic mode' => ['frankenphp', [], ['frankenphp_finish_request'], false];
        yield 'FrankenPHP worker mode' => ['frankenphp', ['FRANKENPHP_WORKER' => '1'], ['frankenphp_finish_request'], false];
        yield 'CLI' => ['cli', [], [], false];
        yield 'phpdbg' => ['phpdbg', [], [], false];
    }

    /**
     * D59: a per-request web SAPI without a finish-request function sends a response only when the script ends.
     *
     * @param array<string, string> $server
     * @param list<string>          $available
     */
    #[DataProvider('requestEnds')]
    public function testResponseEndsWithTheScript(string $sapi, array $server, array $available, bool $expected): void
    {
        $functionExists = static fn(string $name): bool => in_array($name, $available, true);

        $this->assertSame($expected, FinishRequest::responseEndsWithTheScript($sapi, $server, $functionExists, $this->environment(false)));
    }

    /** FrankenPHP worker mode from the environment is no per-request SAPI, even without a finish-request function. */
    public function testResponseEndsWithTheScriptReadsWorkerModeFromTheEnvironment(): void
    {
        $none = static fn(string $name): bool => false;

        $this->assertFalse(FinishRequest::responseEndsWithTheScript('frankenphp', [], $none, $this->environment('1')));
        $this->assertTrue(FinishRequest::responseEndsWithTheScript('frankenphp', [], $none, $this->environment(false)));
        $this->assertTrue(FinishRequest::outlivesRequest('frankenphp', [], $this->environment('1')));
    }

    public function testDetectFindsNothingUnderTheCliTestRunner(): void
    {
        $this->assertSame('cli', PHP_SAPI);
        $this->assertNull(FinishRequest::detect());
    }

    public function testClosureForIgnoresUserAbortThenCallsTheFunction(): void
    {
        $previous = ignore_user_abort();

        try {
            ignore_user_abort(false);
            $finish = FinishRequest::closureFor(self::class . '::probe');
            $this->assertInstanceOf(\Closure::class, $finish);
            $this->assertSame([], self::$probeCalls, 'nothing runs before the closure is called');

            $finish();

            $this->assertSame([1], self::$probeCalls);
        } finally {
            ignore_user_abort((bool) $previous);
        }
    }

    public function testClosureForNeedsACallableFunction(): void
    {
        $this->assertNull(FinishRequest::closureFor(null));
        $this->assertNull(FinishRequest::closureFor('agentcat_no_such_function'));
    }

    /** @return \Closure(string): (string|false) a `getenv()` that knows only `FRANKENPHP_WORKER` */
    private function environment(string|false $worker): \Closure
    {
        return static fn(string $name): string|false => $name === 'FRANKENPHP_WORKER' ? $worker : false;
    }

    public static function probe(): void
    {
        self::$probeCalls[] = ignore_user_abort();
    }
}
