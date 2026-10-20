<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

/** AgentCat's log lines of a listing or a registry rebuild, in full, for tests that assert the exact lines. */
final class ListingLines
{
    public const string REBUILT = 'Rebuilt injection registries on demand (tools/call before tools/list on this instance)';

    public const string SHADOWED = 'WARN: This server registers its own "get_more_tools" tool, which is shadowed by AgentCat\'s report-missing tool: calls to it are answered by AgentCat and never reach your handler. Rename your tool, or set enableReportMissing: false to keep yours reachable.';

    public const string MISSING_TOOL_REPORTED = 'Missing tool reported (context length: %d)';

    /** The line logged once per tracked server for a tool that declares its own `session_id`. */
    public static function ownSessionId(string $tool): string
    {
        return sprintf('ERROR: Tool "%s" already declares a \'session_id\' parameter. AgentCat will not inject its own, and calls to this tool are published without a session, so they cannot be correlated. Your parameter is untouched and still reaches your handler. If you already manage sessions, pass a resolveSessionId hook to track() — AgentCat will derive its session from your identifier and stop injecting session_id entirely.', $tool);
    }

    /**
     * The lines of a call, after a rebuild, for a customer's `get_more_tools` that declares its own `session_id` and that
     * AgentCat's tool shadows.
     *
     * @return list<string>
     */
    public static function shadowedCall(int $contextLength): array
    {
        return [self::SHADOWED, self::ownSessionId('get_more_tools'), self::REBUILT, sprintf(self::MISSING_TOOL_REPORTED, $contextLength)];
    }
}
