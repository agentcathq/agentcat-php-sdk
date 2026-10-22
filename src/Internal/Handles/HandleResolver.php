<?php

declare(strict_types=1);

namespace AgentCat\Internal\Handles;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Id\SessionId;
use AgentCat\Internal\Support\Js;

/** @internal */
final class HandleResolver
{
    /**
     * @param mixed $arguments the decoded `params.arguments`
     */
    public static function resolve(
        mixed $arguments,
        bool $hookMode,
        bool $enableAgentTracking,
        bool $sessionParamIsOurs,
    ): HandleResolution {
        if ($hookMode) {
            $sessionId = '';
            $source = SessionIdSource::Hook;
        } elseif (!$sessionParamIsOurs) {
            $sessionId = '';
            $source = SessionIdSource::Foreign;
        } else {
            $supplied = self::extractHandle($arguments, Constants::PARAM_SESSION_ID);
            if ($supplied !== null && strtolower($supplied) !== Constants::SESSION_START_SENTINEL) {
                if (SessionId::isValid($supplied)) {
                    $sessionId = $supplied;
                    $source = SessionIdSource::Supplied;
                } else {
                    $sessionId = '';
                    $source = SessionIdSource::Invalid;
                }
            } else {
                $sessionId = SessionId::mint();
                $source = SessionIdSource::Minted;
            }
        }

        $agentId = $enableAgentTracking ? self::extractHandle($arguments, Constants::PARAM_AGENT_ID) : null;

        return new HandleResolution($sessionId, $source, $agentId, $hookMode);
    }

    private static function extractHandle(mixed $arguments, string $name): ?string
    {
        if (!$arguments instanceof \stdClass || !property_exists($arguments, $name)) {
            return null;
        }
        $value = $arguments->{$name};
        if (!is_string($value)) {
            return null;
        }
        $trimmed = Js::trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
