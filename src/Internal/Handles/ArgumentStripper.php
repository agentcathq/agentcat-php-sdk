<?php

declare(strict_types=1);

namespace AgentCat\Internal\Handles;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Tools\GetMoreTools;

/** @internal */
final class ArgumentStripper
{
    /**
     * Returns a shallow copy of the arguments without the injected keys; the input is never modified.
     *
     * @param array<array-key, list<string>>|null $registry injected parameter names per tool (whole server)
     */
    public static function strip(mixed $arguments, string $toolName, ?array $registry): mixed
    {
        if (!$arguments instanceof \stdClass) {
            return $arguments;
        }

        $copy = clone $arguments;
        foreach (self::names($toolName, $registry) as $name) {
            unset($copy->{$name});
        }

        return $copy;
    }

    /**
     * The parameter names strip() removes: the registry entry, else the heuristic for unlisted tools.
     *
     * @param array<array-key, list<string>>|null $registry
     * @return list<string>
     */
    public static function names(string $toolName, ?array $registry): array
    {
        if ($registry !== null && array_key_exists($toolName, $registry)) {
            return $registry[$toolName];
        }
        if ($toolName === GetMoreTools::NAME) {
            return [Constants::PARAM_SESSION_ID, Constants::PARAM_AGENT_ID];
        }

        return [Constants::PARAM_SESSION_ID, Constants::PARAM_AGENT_ID, Constants::PARAM_CONTEXT];
    }
}
