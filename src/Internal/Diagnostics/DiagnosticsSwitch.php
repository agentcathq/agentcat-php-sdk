<?php

declare(strict_types=1);

namespace AgentCat\Internal\Diagnostics;

use AgentCat\Internal\Support\Js;

/**
 * TypeScript `initDiagnostics`'s decision: the `disableDiagnostics` option, then `DISABLE_DIAGNOSTICS`, then the test
 * environment.
 *
 * @internal
 */
final class DiagnosticsSwitch
{
    public const string UNSET = 'unset';

    public const string DISABLED = 'disabled';

    public const string FORCE_ENABLED = 'force-enabled';

    /**
     * TS `envDiagnosticsFlag`: blank is unset; `false`, `0`, `no` or `off` (trimmed, any case) is a deliberate opt-in
     * that beats the test-environment default; any other value disables.
     */
    public static function flag(?string $raw): string
    {
        $value = strtolower(Js::trim($raw ?? ''));
        if ($value === '') {
            return self::UNSET;
        }

        return in_array($value, ['false', '0', 'no', 'off'], true) ? self::FORCE_ENABLED : self::DISABLED;
    }

    public static function enabled(bool $disabledOption, ?string $raw, bool $testEnvironment): bool
    {
        $flag = self::flag($raw);

        return !$disabledOption && $flag !== self::DISABLED && ($flag === self::FORCE_ENABLED || !$testEnvironment);
    }
}
