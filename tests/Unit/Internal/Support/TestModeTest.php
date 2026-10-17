<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Support;

use AgentCat\Internal\Support\TestMode;
use AgentCat\Tests\Support\Environment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TestModeTest extends TestCase
{
    private Environment $environment;

    #[\Override]
    protected function setUp(): void
    {
        $this->environment = Environment::clear('APP_ENV', 'AGENTCAT_ENABLED');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->environment->restore();
    }

    public function testPhpUnitIsATestEnvironmentAndTurnsTheSdkOff(): void
    {
        $this->assertTrue(TestMode::inTestEnvironment());
        $this->assertFalse(TestMode::sdkEnabled());
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function appEnvironments(): iterable
    {
        yield 'no APP_ENV' => [null, false];
        yield 'empty' => ['', false];
        yield 'testing, padded and capitalized' => [' Testing ', true];
        yield 'testing' => ['testing', true];
        yield 'test' => ['test', true];
        yield 'TEST' => ['TEST', true];
        yield 'tests' => ['tests', false];
        yield 'production' => ['production', false];
        yield 'local' => ['local', false];
    }

    #[DataProvider('appEnvironments')]
    public function testWithoutATestFrameworkAppEnvDecides(?string $appEnv, bool $testing): void
    {
        if ($appEnv !== null) {
            putenv('APP_ENV=' . $appEnv);
        }
        $noFramework = static fn(string $class): bool => false;

        $this->assertSame($testing, TestMode::inTestEnvironment($noFramework));
        $this->assertSame(!$testing, TestMode::sdkEnabled($noFramework));
    }

    public function testAppEnvIsReadFromServerVariablesToo(): void
    {
        $_SERVER['APP_ENV'] = 'testing';

        $this->assertTrue(TestMode::inTestEnvironment(static fn(string $class): bool => false));
    }

    /** @return iterable<string, array{string}> */
    public static function runnerClasses(): iterable
    {
        yield 'PHPUnit' => [TestCase::class];
        // DECISIONS item 6: Pest's sequential runner does not define PHPUNIT_COMPOSER_INSTALL.
        yield 'Pest' => ['Pest\TestSuite'];
    }

    #[DataProvider('runnerClasses')]
    public function testALoadedTestRunnerClassIsATestEnvironment(string $runner): void
    {
        putenv('APP_ENV=production');
        $only = static fn(string $class): bool => $class === $runner;

        $this->assertTrue(TestMode::inTestEnvironment($only));
        $this->assertFalse(TestMode::sdkEnabled($only));
    }

    public function testTheDefaultProbeNeverAutoloads(): void
    {
        $this->assertTrue(TestMode::isLoaded(TestCase::class)); // also loads TestMode before the spy is in place
        $requested = [];
        $spy = static function (string $class) use (&$requested): void {
            $requested[] = $class;
        };
        spl_autoload_register($spy, true, true);

        try {
            $loaded = TestMode::isLoaded('AgentCat\Tests\Unit\Internal\Support\NoSuchRunner');
        } finally {
            spl_autoload_unregister($spy);
        }

        $this->assertFalse($loaded);
        $this->assertSame([], $requested, 'an installed but unused PHPUnit must not count');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function enabledValues(): iterable
    {
        yield 'true' => ['true', true];
        yield 'TRUE, padded' => [' TRUE ', true];
        yield '1' => ['1', true];
        yield 'yes' => ['yes', true];
        yield 'on' => ['on', true];
        yield 'false' => ['false', false];
        yield '0' => ['0', false];
        yield 'empty' => ['', false];
        yield 'enabled' => ['enabled', false];
    }

    #[DataProvider('enabledValues')]
    public function testAgentCatEnabledTurnsTheSdkOnUnderPhpUnit(string $value, bool $enabled): void
    {
        putenv('AGENTCAT_ENABLED=' . $value);

        $this->assertSame($enabled, TestMode::sdkEnabled());
        $this->assertTrue(TestMode::inTestEnvironment(), 'AGENTCAT_ENABLED does not change the detection');
    }

    public function testOutsideATestEnvironmentTheSdkIsOnWhateverAgentCatEnabledSays(): void
    {
        putenv('AGENTCAT_ENABLED=false');

        $this->assertTrue(TestMode::sdkEnabled(static fn(string $class): bool => false));
    }
}
