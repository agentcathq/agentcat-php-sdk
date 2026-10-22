<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Diagnostics;

use AgentCat\Internal\Diagnostics\DiagnosticsSwitch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class DiagnosticsSwitchTest extends TestCase
{
    #[Group('ac-06-47')]
    #[Group('ac-01-60')]
    public function testWithoutOptionOrVariableOutsideTestsDiagnosticsAreOn(): void
    {
        $this->assertTrue(DiagnosticsSwitch::enabled(false, null, null, false));
    }

    /** @return iterable<string, array{string}> */
    public static function disablingValues(): iterable
    {
        foreach (['true', 'TRUE', '1', 'yes', 'on', 'anything', ' true '] as $value) {
            yield $value => [$value];
        }
    }

    #[DataProvider('disablingValues')]
    #[Group('ac-06-49')]
    #[Group('ac-01-62')]
    public function testATrueOrUnknownValueTurnsDiagnosticsOff(string $value): void
    {
        $this->assertSame(DiagnosticsSwitch::DISABLED, DiagnosticsSwitch::flag($value));
        $this->assertFalse(DiagnosticsSwitch::enabled(false, $value, null, false));
        $this->assertFalse(DiagnosticsSwitch::enabled(false, $value, null, true));
    }

    /** @return iterable<string, array{string}> */
    public static function enablingValues(): iterable
    {
        foreach (['false', 'FALSE', '0', 'no', 'off', ' Off ', "\u{a0}no\t"] as $value) {
            yield $value => [$value];
        }
    }

    #[DataProvider('enablingValues')]
    #[Group('ac-06-50')]
    #[Group('ac-01-63')]
    public function testAFalseValueTurnsDiagnosticsOnEvenUnderATestRunner(string $value): void
    {
        $this->assertSame(DiagnosticsSwitch::FORCE_ENABLED, DiagnosticsSwitch::flag($value));
        $this->assertTrue(DiagnosticsSwitch::enabled(false, $value, null, true));
        $this->assertTrue(DiagnosticsSwitch::enabled(false, $value, null, false));
    }

    /** @return iterable<string, array{?string}> */
    public static function unsetValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'blanks' => ['  '];
    }

    #[DataProvider('unsetValues')]
    #[Group('ac-06-51')]
    #[Group('ac-01-64')]
    public function testABlankValueLetsTheTestEnvironmentDecide(?string $value): void
    {
        $this->assertSame(DiagnosticsSwitch::UNSET, DiagnosticsSwitch::flag($value));
        $this->assertTrue(DiagnosticsSwitch::enabled(false, $value, null, false));
        $this->assertFalse(DiagnosticsSwitch::enabled(false, $value, null, true));
    }

    /** A10 (D50): `DISABLE_DIAGNOSTICS`, the unprefixed name, can only turn diagnostics off. */
    #[Group('ac-06-49')]
    public function testTheUnprefixedFallbackCanOnlyTurnDiagnosticsOff(): void
    {
        $this->assertFalse(DiagnosticsSwitch::enabled(false, null, 'true', false), 'true turns them off');
        $this->assertFalse(DiagnosticsSwitch::enabled(false, ' ', 'anything', false), 'any other value too');
        $this->assertFalse(DiagnosticsSwitch::enabled(false, null, 'false', true), 'false never turns them on');
        $this->assertTrue(DiagnosticsSwitch::enabled(false, null, 'false', false), 'and does not turn them off');
        $this->assertTrue(DiagnosticsSwitch::enabled(false, null, '', false));
    }

    /** D50 ruling: when the two names disagree, any off value wins; a false value never turns them on over a true one. */
    #[Group('ac-06-49')]
    public function testWhenTheTwoVariablesDisagreeOffWins(): void
    {
        $this->assertFalse(DiagnosticsSwitch::enabled(false, 'false', 'true', true), 'DISABLE_DIAGNOSTICS=true over AGENTCAT_DISABLE_DIAGNOSTICS=false');
        $this->assertFalse(DiagnosticsSwitch::enabled(false, 'false', 'true', false));
        $this->assertFalse(DiagnosticsSwitch::enabled(false, 'false', 'anything', false), 'any off word');
        $this->assertFalse(DiagnosticsSwitch::enabled(false, 'true', 'false', false), 'AGENTCAT_DISABLE_DIAGNOSTICS=true over DISABLE_DIAGNOSTICS=false');
        $this->assertTrue(DiagnosticsSwitch::enabled(false, 'false', 'false', true), 'two false values agree: on, also under a test runner');
    }

    #[Group('ac-06-48')]
    public function testTheOptionWinsOverTheVariable(): void
    {
        $this->assertFalse(DiagnosticsSwitch::enabled(true, 'false', null, false));
        $this->assertFalse(DiagnosticsSwitch::enabled(true, null, null, false));
    }
}
