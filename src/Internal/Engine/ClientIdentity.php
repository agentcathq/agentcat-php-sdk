<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

use AgentCat\Internal\Support\Values;

/**
 * Picks the client name and version from a ladder of candidates (TS `narrowClientInfo`).
 *
 * @internal
 */
final class ClientIdentity
{
    /**
     * The first candidate with a string `name` or `version` wins. Each field is narrowed on its own:
     * a field that is not a string becomes null. Arrays and objects with public properties are read alike.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function pick(mixed ...$candidates): array
    {
        foreach ($candidates as $candidate) {
            if (!Values::isObjectLike($candidate)) {
                continue;
            }
            $name = Values::get($candidate, 'name');
            $version = Values::get($candidate, 'version');
            $name = is_string($name) ? $name : null;
            $version = is_string($version) ? $version : null;
            if ($name !== null || $version !== null) {
                return [$name, $version];
            }
        }

        return [null, null];
    }
}
