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

        if ($registry !== null && array_key_exists($toolName, $registry)) {
            $names = $registry[$toolName];
        } elseif ($toolName === GetMoreTools::NAME) {
            $names = [Constants::PARAM_SESSION_ID, Constants::PARAM_AGENT_ID];
        } else {
            $names = [Constants::PARAM_SESSION_ID, Constants::PARAM_AGENT_ID, Constants::PARAM_CONTEXT];
        }

        $copy = clone $arguments;
        foreach ($names as $name) {
            unset($copy->{$name});
        }

        return $copy;
    }
}
