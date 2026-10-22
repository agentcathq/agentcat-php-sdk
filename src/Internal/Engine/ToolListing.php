<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\ErrorText;

/** @internal */
final class ToolListing
{
    /** The listing is served as the library answered it, after this line (TS listWrap, ErrorText::message()). */
    public static function injectionFailed(\Throwable $e): void
    {
        Log::write('Warning: AgentCat tool-list injection failed; serving the original tool list unmodified - ' . ErrorText::message($e));
    }

    /**
     * TS listWrap after the original handler: a listing without a tool list or with an empty one is served as is
     * (`!Array.isArray(tools) || tools.length === 0`, B11, D15); a degraded injection keeps the previous registries
     * (Plan A ledger carry).
     *
     * @param list<mixed>|null $tools null when the answer has no tool list
     * @return list<mixed>|null the tools to advertise, or null to serve the original listing
     */
    public static function apply(TrackedServer $server, ?array $tools): ?array
    {
        if ($tools === null || $tools === []) {
            Log::write(ToolListInjector::NO_TOOLS_WARNING);

            return null;
        }
        $list = ToolListInjector::inject($tools, $server->injectionConfig(), $server->injection);
        if ($list->degraded) {
            return null;
        }
        $server->adopt($list);

        return $list->tools;
    }

    /**
     * TS ensureRegistries (D17: no cache; runs on every call until a listing succeeds). No time bound in synchronous PHP.
     *
     * @param \Closure(): (list<mixed>|null) $listOriginal the library's own first page; may throw
     */
    public static function ensureRegistries(TrackedServer $server, \Closure $listOriginal): void
    {
        if ($server->registry !== null) {
            return;
        }
        try {
            $tools = $listOriginal();
            if ($tools === null) {
                return;
            }
            $list = ToolListInjector::inject($tools, $server->injectionConfig(), $server->injection);
            if ($list->degraded) {
                return;
            }
            $server->adopt($list);
            Log::write('Rebuilt injection registries on demand (tools/call before tools/list on this instance)');
        } catch (\Throwable $e) {
            Log::write('Warning: registry rebuild-on-demand failed - ' . ErrorText::of($e));
        }
    }
}
