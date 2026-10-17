<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

use AgentCat\Event;
use AgentCat\Identity;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Handles\HandleTags;
use AgentCat\Internal\Id\SessionId;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\ErrorText;
use AgentCat\Internal\Support\Js;
use AgentCat\Internal\Tags\TagValidator;
use AgentCat\ToolCall;

/**
 * Turns the customer hooks of a tool call into closures that the event queue runs later (D04), in TypeScript order.
 *
 * @internal
 */
final class PendingHooks
{
    /**
     * @return list<\Closure(Event): void>
     */
    public static function build(TrackedServer $server, ToolCall $call): array
    {
        $options = $server->options;
        $projectId = $server->projectId !== '' ? $server->projectId : null;
        $pending = [];
        if ($options->resolveSessionId instanceof \Closure) {
            $hook = $options->resolveSessionId;
            $pending[] = self::guarded('resolveSessionId', static function (Event $event) use ($hook, $call, $projectId): void {
                [$sessionId, $source] = SessionId::fromHookValue(self::invoke($hook, $call, 'resolveSessionId hook error: '), $projectId);
                $event->sessionId = $sessionId;
                $tags = $event->tags ?? [];
                $tags[Constants::TAG_SESSION_SOURCE] = $source->value;
                $event->tags = $tags;
            });
        }
        if ($options->identify instanceof \Closure) {
            $hook = $options->identify;
            $pending[] = self::guarded('identify', static function (Event $event) use ($hook, $call): void {
                $identity = self::invoke($hook, $call, 'Error: identify callback threw - ');
                if ($identity === null) {
                    return;
                }
                if (!$identity instanceof Identity) {
                    Log::write(sprintf('WARN: identify returned %s, expected AgentCat\Identity or null; the event stays anonymous', get_debug_type($identity)));

                    return;
                }
                $event->identifyActorGivenId = $identity->userId;
                $event->identifyActorName = $identity->userName;
                $event->identifyActorData = $identity->userData === [] ? new \stdClass() : $identity->userData; // TS `userData || {}`
            });
        }
        if ($options->eventTags instanceof \Closure) {
            $hook = $options->eventTags;
            $pending[] = self::guarded('eventTags', static function (Event $event) use ($hook, $call): void {
                $raw = self::invoke($hook, $call, 'eventTags callback error: ');
                if (!Js::truthy($raw)) {
                    return;
                }
                $valid = TagValidator::validate($raw);
                if ($valid !== null) {
                    $event->tags = HandleTags::merge($valid, $event->tags ?? []); // customer first, SDK tags win (B13, D15)
                }
            });
        }
        if ($options->eventProperties instanceof \Closure) {
            $hook = $options->eventProperties;
            $pending[] = self::guarded('eventProperties', static function (Event $event) use ($hook, $call): void {
                $raw = self::invoke($hook, $call, 'eventProperties callback error: ');
                if (!Js::truthy($raw)) {
                    return;
                }
                $event->properties = $raw === [] ? new \stdClass() : $raw; // TS keeps {} on tool calls
            });
        }

        return $pending;
    }

    /**
     * Runs a step; a throw is logged as `Warning: <label> hook failed in background - <error>` and goes no further.
     *
     * @param \Closure(Event): void $apply
     *
     * @return \Closure(Event): void
     */
    public static function guarded(string $label, \Closure $apply): \Closure
    {
        return static function (Event $event) use ($label, $apply): void {
            try {
                $apply($event);
            } catch (\Throwable $e) {
                Log::write(sprintf('Warning: %s hook failed in background - %s', $label, ErrorText::of($e)));
            }
        };
    }

    /**
     * Calls a customer hook. A throw, including a `TypeError` from a wrong signature, is logged with the hook's own line.
     *
     * @param \Closure(ToolCall): mixed $hook
     */
    private static function invoke(\Closure $hook, ToolCall $call, string $prefix): mixed
    {
        try {
            return $hook($call);
        } catch (\Throwable $e) {
            Log::write($prefix . ErrorText::of($e));

            return null;
        }
    }
}
