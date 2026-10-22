<?php

declare(strict_types=1);

namespace AgentCat\Internal\Id;

use AgentCat\Internal\Handles\SessionIdSource;
use AgentCat\Internal\Support\Js;

/** @internal */
final class SessionId
{
    /** `\z` (not `$`) so a trailing newline does not match. */
    public const string PATTERN = '/\Ases_[0-9A-Za-z]{27}\z/';

    public static function mint(): string
    {
        return 'ses_' . Ksuid::random();
    }

    /** Deterministic session id; must match the TypeScript `deriveSessionId` byte for byte. */
    public static function derive(string $id, ?string $projectId): string
    {
        $input = ($projectId !== null && $projectId !== '') ? $id . ':' . $projectId : $id;
        $hash = hash('sha256', $input, true);
        $u32 = Ksuid::readUint32($hash);
        $timestampMs = 1_704_067_200_000 + ($u32 % 31_536_000_000);

        return 'ses_' . Ksuid::fromParts($timestampMs, substr($hash, 4, 16));
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * @return array{0: string, 1: SessionIdSource}
     */
    public static function fromHookValue(mixed $value, ?string $projectId): array
    {
        if (is_string($value)) {
            $trimmed = Js::trim($value);
            if ($trimmed !== '') {
                return [self::derive($trimmed, $projectId), SessionIdSource::Hook];
            }
        }

        return [self::mint(), SessionIdSource::Minted];
    }
}
