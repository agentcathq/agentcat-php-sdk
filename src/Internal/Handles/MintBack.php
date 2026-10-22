<?php

declare(strict_types=1);

namespace AgentCat\Internal\Handles;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Support\Js;

/**
 * Tells the agent its session id: a text block in `content` and an `mcp_session` mirror.
 *
 * Results are decoded JSON (objects are `\stdClass`, arrays are lists). Nothing here mutates
 * its input; only the levels that change are copied.
 *
 * @internal
 */
final class MintBack
{
    /** The text block for a resolution, or null when nothing is announced. */
    public static function text(HandleResolution $resolution): ?string
    {
        if ($resolution->hookMode) {
            return null;
        }

        return match ($resolution->source) {
            SessionIdSource::Minted => implode("\n", [
                Constants::MINT_BACK_HEADER_ISSUED,
                'session_id: ' . $resolution->sessionId,
                Constants::MINT_BACK_ISSUED_BODY,
            ]),
            SessionIdSource::Invalid => implode("\n", [
                Constants::MINT_BACK_HEADER_UNRECOGNIZED,
                Constants::MINT_BACK_UNRECOGNIZED_BODY,
            ]),
            default => null,
        };
    }

    /** Returns a copy of the result with the text block as the first `content` item. */
    public static function prependText(mixed $result, string $text): mixed
    {
        if (!$result instanceof \stdClass || !property_exists($result, 'content') || !is_array($result->content) || !array_is_list($result->content)) {
            return $result;
        }

        $block = new \stdClass();
        $block->type = 'text';
        $block->text = $text;

        $copy = clone $result;
        $copy->content = [$block, ...$result->content];

        return $copy;
    }

    /** The `mcp_session` payload (keys `session_id`, `agent_id`, `status`), or null when empty. */
    public static function structuredPayload(HandleResolution $resolution): ?\stdClass
    {
        $source = $resolution->source;
        $sessionOurs = !$resolution->hookMode && $source !== SessionIdSource::Foreign;
        $payload = new \stdClass();

        if ($sessionOurs && $source !== SessionIdSource::Invalid) {
            $payload->session_id = $resolution->sessionId;
        }
        if (Js::truthy($resolution->agentId)) {
            $payload->agent_id = $resolution->agentId;
        }
        if ($sessionOurs) {
            $payload->status = match ($source) {
                SessionIdSource::Minted => 'issued',
                SessionIdSource::Invalid => 'unrecognized',
                default => 'active',
            };
        }

        return get_object_vars($payload) === [] ? null : $payload;
    }

    /** Returns a copy whose `structuredContent` starts with `mcp_session`; customer data wins. */
    public static function mirror(mixed $result, \stdClass $payload): mixed
    {
        if (!$result instanceof \stdClass || !property_exists($result, 'structuredContent')) {
            return $result;
        }
        $content = $result->structuredContent;
        if (!$content instanceof \stdClass || property_exists($content, Constants::MCP_SESSION_KEY)) {
            return $result;
        }

        $mirrored = new \stdClass();
        $mirrored->{Constants::MCP_SESSION_KEY} = $payload;
        foreach (get_object_vars($content) as $key => $value) {
            $mirrored->{(string) $key} = $value;
        }

        $copy = clone $result;
        $copy->structuredContent = $mirrored;

        return $copy;
    }

    /** Applies the text block and, when allowed, the structured mirror. */
    public static function decorate(mixed $result, HandleResolution $resolution, bool $mirrorAllowed): mixed
    {
        if ($result instanceof \stdClass && ($result->resultType ?? null) === 'input_required') {
            return $result;
        }

        $text = self::text($resolution);
        if ($text !== null) {
            $result = self::prependText($result, $text);
        }
        if ($mirrorAllowed) {
            $payload = self::structuredPayload($resolution);
            if ($payload instanceof \stdClass) {
                $result = self::mirror($result, $payload);
            }
        }

        return $result;
    }
}
