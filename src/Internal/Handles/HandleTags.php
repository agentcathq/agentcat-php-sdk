<?php

declare(strict_types=1);

namespace AgentCat\Internal\Handles;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Support\Js;
use AgentCat\Internal\Tools\GetMoreTools;

/** @internal */
final class HandleTags
{
    private const int AGENT_ID_MAX_UNITS = 200;

    /**
     * @return array<string, string>
     */
    public static function build(HandleResolution $resolution, ?string $protocolVersion): array
    {
        $tags = [Constants::TAG_SESSION_SOURCE => $resolution->source->value];
        if ($resolution->agentId !== null && $resolution->agentId !== '') {
            $clean = str_replace(["\r", "\n"], ' ', $resolution->agentId);
            $tags[Constants::TAG_AGENT_ID] = Js::utf16Slice($clean, self::AGENT_ID_MAX_UNITS);
            $tags[Constants::TAG_AGENT_SOURCE] = 'supplied';
        }
        if ($protocolVersion !== null && $protocolVersion !== '') {
            $tags[Constants::TAG_PROTOCOL_VERSION] = $protocolVersion;
        }

        return $tags;
    }

    /**
     * Customer tags first; SDK tags overwrite the same keys.
     *
     * @param array<array-key, string>|null $customerTags
     * @param array<array-key, string> $sdkTags
     *
     * @return array<array-key, string>
     */
    public static function merge(?array $customerTags, array $sdkTags): array
    {
        $merged = $customerTags ?? [];
        foreach ($sdkTags as $key => $value) {
            $merged[$key] = $value;
        }

        return $merged;
    }

    /** The raw `context` argument (any JSON type, untrimmed) or null. */
    public static function userIntent(mixed $arguments, string $toolName, bool $enableToolCallContext): mixed
    {
        if (!$arguments instanceof \stdClass || !property_exists($arguments, Constants::PARAM_CONTEXT)) {
            return null;
        }
        if ($toolName === GetMoreTools::NAME || $enableToolCallContext) {
            return $arguments->{Constants::PARAM_CONTEXT};
        }

        return null;
    }
}
