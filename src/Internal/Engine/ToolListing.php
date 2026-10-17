<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\ErrorText;

/** @internal */
final class ToolListing
{
    /**
     * TS listWrap after the original handler: an empty listing is served as is (B11, D15); a degraded injection keeps
     * the previous registries (Plan A ledger carry).
     *
     * @param list<mixed> $tools
     * @return list<mixed>|null the tools to advertise, or null to serve the original listing
     */
    public static function apply(TrackedServer $server, array $tools): ?array
    {
        if ($tools === []) {
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
