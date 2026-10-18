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
        $this->assertTrue(DiagnosticsSwitch::enabled(false, null, false));
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
        $this->assertFalse(DiagnosticsSwitch::enabled(false, $value, false));
        $this->assertFalse(DiagnosticsSwitch::enabled(false, $value, true));
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
        $this->assertTrue(DiagnosticsSwitch::enabled(false, $value, true));
        $this->assertTrue(DiagnosticsSwitch::enabled(false, $value, false));
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
        $this->assertTrue(DiagnosticsSwitch::enabled(false, $value, false));
        $this->assertFalse(DiagnosticsSwitch::enabled(false, $value, true));
    }

    #[Group('ac-06-48')]
    public function testTheOptionWinsOverTheVariable(): void
    {
        $this->assertFalse(DiagnosticsSwitch::enabled(true, 'false', false));
        $this->assertFalse(DiagnosticsSwitch::enabled(true, null, false));
    }
}
