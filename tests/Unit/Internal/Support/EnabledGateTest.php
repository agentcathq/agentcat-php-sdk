<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Support;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\EnabledGate;
use AgentCat\Internal\Support\TestMode;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\MemoryLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnabledGateTest extends TestCase
{
    private Environment $environment;

    private MemoryLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        TestMode::reset(); // an earlier test can have logged the once-per-process line of AGENTCAT_ENABLED
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

    public function testTheConfigWordsDecide(): void
    {
        $this->assertTrue((EnabledGate::of('true'))());
        $this->assertTrue((EnabledGate::of(true))());
        $this->assertFalse((EnabledGate::of('off'))());
        $this->assertFalse((EnabledGate::of(false))());
        $this->assertTrue((EnabledGate::of(' YES '))());
        $this->assertTrue((EnabledGate::of(1))());
        $this->assertFalse((EnabledGate::of(0))());
        $this->assertSame([], $this->logger->messages());
    }

    public function testUnsetFallsBackToTheTestModeGate(): void
    {
        $this->assertFalse((EnabledGate::of(null))()); // PHPUnit is loaded (D31)
        $this->assertFalse((EnabledGate::of(''))());
        $this->assertFalse((EnabledGate::of('  '))());
        $this->assertSame([], $this->logger->messages());
    }

    public function testUnsetReadsTheEnvironmentWhenTheGateRuns(): void
    {
        $gate = EnabledGate::of(null);
        putenv('AGENTCAT_ENABLED=true');

        $this->assertTrue($gate()); // TestMode::sdkEnabled(): AGENTCAT_ENABLED of the process (D44)
    }

    public function testAnUnknownWordLogsOnceAndUsesTheDefault(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $gate = EnabledGate::of('maybe');

        $this->assertFalse($gate());
        $this->assertFalse($gate());
        $this->assertSame(['WARN: AgentCat config "agentcat.enabled" is "maybe", not one of true, 1, yes, on, false, 0, no, off; the default is used'], $logger->messages());
    }

    /**
     * Review Focus 3: `AGENTCAT_ENABLED=maybe` gives `agentcat.enabled` the same word. The default is the test-mode
     * default: reading the variable again would log a second line for the same value.
     */
    public function testAnUnknownWordThatIsAlsoTheEnvironmentLogsOneLine(): void
    {
        putenv('AGENTCAT_ENABLED=maybe');
        $gate = EnabledGate::of('maybe');

        $this->assertFalse($gate());
        $this->assertSame(['WARN: AgentCat config "agentcat.enabled" is "maybe", not one of true, 1, yes, on, false, 0, no, off; the default is used'], $this->logger->messages());
    }

    public function testAnUnknownWordFallsBackToTheEnvironmentOfTheProcess(): void
    {
        putenv('AGENTCAT_ENABLED=false');
        $off = EnabledGate::of('maybe');
        putenv('AGENTCAT_ENABLED=true');
        $on = EnabledGate::of('maybe');
        putenv('AGENTCAT_ENABLED');
        $unset = EnabledGate::of('maybe');

        putenv('AGENTCAT_ENABLED=false');
        $this->assertFalse($off());
        putenv('AGENTCAT_ENABLED=true');
        $this->assertTrue($on()); // forced on, also in a test environment (D44)
        putenv('AGENTCAT_ENABLED');
        $this->assertFalse($unset()); // PHPUnit is loaded: the default (D31)
        $this->assertCount(3, $this->logger->messages()); // one line for each unknown word, none for the variable
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function unknownValues(): iterable
    {
        yield 'an int' => [2, '2'];
        yield 'a float' => [1.5, '1.5'];
        yield 'an array' => [['on'], 'array'];
        yield 'an object' => [new \stdClass(), 'stdClass'];
    }

    #[DataProvider('unknownValues')]
    public function testAValueOfAnotherTypeIsNamedInTheLine(mixed $value, string $named): void
    {
        $this->assertFalse((EnabledGate::of($value))());
        $this->assertSame([sprintf('WARN: AgentCat config "agentcat.enabled" is "%s", not one of true, 1, yes, on, false, 0, no, off; the default is used', $named)], $this->logger->messages());
    }
}
