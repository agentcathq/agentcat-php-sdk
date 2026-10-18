<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Support;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\TestMode;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\MemoryLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TestModeTest extends TestCase
{
    private Environment $environment;

    private MemoryLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->environment = Environment::clear('APP_ENV', 'AGENTCAT_ENABLED');
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
    }

    #[\Override]
    protected function tearDown(): void
    {
        TestMode::reset();
        Log::reset();
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

    /** @return iterable<string, array{string}> */
    public static function trueValues(): iterable
    {
        yield 'true' => ['true'];
        yield 'TRUE, padded' => [' TRUE '];
        yield '1' => ['1'];
        yield 'yes' => ['yes'];
        yield 'on' => ['on'];
    }

    #[DataProvider('trueValues')]
    public function testATrueValueTurnsTheSdkOnUnderPhpUnit(string $value): void
    {
        putenv('AGENTCAT_ENABLED=' . $value);

        $this->assertTrue(TestMode::sdkEnabled());
        $this->assertTrue(TestMode::inTestEnvironment(), 'AGENTCAT_ENABLED does not change the detection');
        $this->assertSame([], $this->logger->messages());
    }

    /** @return iterable<string, array{string}> */
    public static function falseValues(): iterable
    {
        yield 'false' => ['false'];
        yield 'FALSE, padded' => [' FALSE '];
        yield '0' => ['0'];
        yield 'no' => ['no'];
        yield 'off' => ['off'];
    }

    #[DataProvider('falseValues')]
    public function testAFalseValueTurnsTheSdkOffEverywhere(string $value): void
    {
        putenv('AGENTCAT_ENABLED=' . $value);

        $this->assertFalse(TestMode::sdkEnabled(static fn(string $class): bool => false), 'D44: outside test environments too');
        $this->assertFalse(TestMode::sdkEnabled());
        $this->assertSame([], $this->logger->messages());
    }

    public function testAnEmptyValueCountsAsUnset(): void
    {
        putenv('AGENTCAT_ENABLED=');

        $this->assertTrue(TestMode::sdkEnabled(static fn(string $class): bool => false));
        $this->assertFalse(TestMode::sdkEnabled());
        $this->assertSame([], $this->logger->messages());
    }

    /** @return iterable<string, array{string}> */
    public static function otherValues(): iterable
    {
        yield 'enabled' => ['enabled'];
        yield 'a typo of false' => ['flase'];
        yield '2' => ['2'];
    }

    #[DataProvider('otherValues')]
    public function testAnyOtherValueKeepsTheDefaultAndIsReportedOnce(string $value): void
    {
        putenv('AGENTCAT_ENABLED=' . $value);

        $this->assertTrue(TestMode::sdkEnabled(static fn(string $class): bool => false), 'on outside test environments');
        $this->assertFalse(TestMode::sdkEnabled(), 'off in test environments');

        $this->assertSame([
            sprintf('WARN: AGENTCAT_ENABLED "%s" is not one of true, 1, yes, on, false, 0, no, off; the default is used', $value),
        ], $this->logger->messages(), 'once per process');
    }

    /** @return iterable<string, array{mixed, ?bool}> */
    public static function words(): iterable
    {
        foreach ([true, 1, 'true', ' YES ', 'on', '1'] as $i => $value) {
            yield 'on ' . $i => [$value, true];
        }
        foreach ([false, 0, 'false', 'No', ' off', '0'] as $i => $value) {
            yield 'off ' . $i => [$value, false];
        }
        foreach ([null, '', '  ', 'maybe', 2, 1.0, []] as $i => $value) {
            yield 'neither ' . $i => [$value, null];
        }
    }

    #[DataProvider('words')]
    public function testTheEnabledWords(mixed $value, ?bool $expected): void
    {
        $this->assertSame($expected, TestMode::word($value));
    }

    /** Plan E1 header conflict 12: a framework bridge's configuration decides before the environment. */
    public function testAConfiguredSwitchDecidesBeforeTheEnvironment(): void
    {
        putenv('AGENTCAT_ENABLED=true');
        TestMode::configure(false);
        $this->assertFalse(TestMode::sdkEnabled());

        putenv('AGENTCAT_ENABLED=off');
        TestMode::configure(true);
        $this->assertTrue(TestMode::sdkEnabled());

        putenv('AGENTCAT_ENABLED=maybe');
        $this->assertTrue(TestMode::sdkEnabled());
        $this->assertSame([], $this->logger->messages()); // the variable is not read
    }

    public function testNoConfiguredSwitchLeavesTheEnvironmentToDecide(): void
    {
        putenv('AGENTCAT_ENABLED=true');
        TestMode::configure(false);

        TestMode::configure(null);

        $this->assertTrue(TestMode::sdkEnabled());
    }

    public function testResetForgetsTheConfiguredSwitch(): void
    {
        TestMode::configure(true);

        TestMode::reset();

        $this->assertFalse(TestMode::sdkEnabled()); // PHPUnit is loaded (D31)
    }

    public function testResetReportsAnOtherValueAgain(): void
    {
        putenv('AGENTCAT_ENABLED=maybe');
        TestMode::sdkEnabled();

        TestMode::reset();
        TestMode::sdkEnabled();

        $this->assertCount(2, $this->logger->messages());
    }
}
