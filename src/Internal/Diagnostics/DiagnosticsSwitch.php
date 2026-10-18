<?php

declare(strict_types=1);

namespace AgentCat\Internal\Diagnostics;

use AgentCat\Internal\Support\Js;

/**
 * TypeScript `initDiagnostics`'s decision: the `disableDiagnostics` option, then `AGENTCAT_DISABLE_DIAGNOSTICS`, then
 * the test environment. While `AGENTCAT_DISABLE_DIAGNOSTICS` is blank, the unprefixed `DISABLE_DIAGNOSTICS` (the
 * TypeScript name) can turn diagnostics off, never on (D50).
 *
 * @internal
 */
final class DiagnosticsSwitch
{
    public const string UNSET = 'unset';

    public const string DISABLED = 'disabled';

    public const string FORCE_ENABLED = 'force-enabled';

    /** The words of `AGENTCAT_DISABLE_DIAGNOSTICS` that keep diagnostics on (trimmed, any case). */
    private const array OFF = ['false', '0', 'no', 'off'];

    /** The words that turn diagnostics off in the `disableDiagnostics` option (`option()`). */
    private const array ON = ['true', '1', 'yes', 'on'];

    /**
     * TS `envDiagnosticsFlag`: blank is unset; `false`, `0`, `no` or `off` (trimmed, any case) is a deliberate opt-in
     * that beats the test-environment default; any other value disables.
     */
    public static function flag(?string $raw): string
    {
        $value = self::word($raw ?? '');
        if ($value === '') {
            return self::UNSET;
        }

        return in_array($value, self::OFF, true) ? self::FORCE_ENABLED : self::DISABLED;
    }

    /**
     * The `disableDiagnostics` option of a config array (`Options::fromArray()`), in the vocabulary of
     * `AGENTCAT_DISABLE_DIAGNOSTICS`: a bool; int 1 or 0; `true`, `1`, `yes` or `on` (trimmed, any case) for true;
     * `false`, `0`, `no`, `off` or blank for false. Null for any other value: the caller decides (the option is an
     * opt-out).
     */
    public static function option(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }
        if (!is_string($value)) {
            return null;
        }
        $word = self::word($value);
        if ($word === '' || in_array($word, self::OFF, true)) {
            return false;
        }

        return in_array($word, self::ON, true) ? true : null;
    }

    /**
     * @param ?string $variable `AGENTCAT_DISABLE_DIAGNOSTICS`
     * @param ?string $fallback `DISABLE_DIAGNOSTICS`, read only while `$variable` is blank; it can only turn
     *                          diagnostics off
     */
    public static function enabled(bool $disabledOption, ?string $variable, ?string $fallback, bool $testEnvironment): bool
    {
        $flag = self::flag($variable);
        if ($flag === self::UNSET && self::flag($fallback) === self::DISABLED) {
            $flag = self::DISABLED; // a false value there never turns diagnostics on
        }

        return !$disabledOption && $flag !== self::DISABLED && ($flag === self::FORCE_ENABLED || !$testEnvironment);
    }

    private static function word(string $raw): string
    {
        return strtolower(Js::trim($raw));
    }
}
