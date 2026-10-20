<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\ErrorText;

/** @internal */
final class ToolListing
{
    /** TS `REGISTRY_REBUILD_TIMEOUT_MS`: a rebuild asks for no further page after this many seconds (D66). */
    public const float REBUILD_BUDGET_SECONDS = 5.0;

    /** The most pages one rebuild reads (D66): 1,500 tools at laravel/mcp's default page size, 5,000 at mcp/sdk's. */
    public const int REBUILD_MAX_PAGES = 100;

    private const string REBUILT = 'Rebuilt injection registries on demand (tools/call before tools/list on this instance)';

    private const string REBUILD_FAILED = 'Warning: registry rebuild-on-demand failed - ';

    private const string REBUILD_STOPPED = 'Warning: registry rebuild-on-demand stopped after page %d - %s';

    /** The listing is served as the library answered it, after this line (TS listWrap, ErrorText::message()). */
    public static function injectionFailed(\Throwable $e): void
    {
        Log::write('Warning: AgentCat tool-list injection failed; serving the original tool list unmodified - ' . ErrorText::message($e));
    }

    /**
     * TS listWrap after the original handler: a listing without a tool list or with an empty one is served as is
     * (`!Array.isArray(tools) || tools.length === 0`, B11, D15); a degraded injection keeps the previous registries
     * (Plan A ledger carry). PHP divergence (D66, B10 fixed): only the last page gets get_more_tools, and each page's
     * registries merge into the server's.
     *
     * @param list<mixed>|null $tools null when the answer has no tool list
     * @param bool $lastPage the answer has no next cursor (`nextCursor()`)
     * @return list<mixed>|null the tools to advertise, or null to serve the original listing
     */
    public static function apply(TrackedServer $server, ?array $tools, bool $lastPage): ?array
    {
        if ($tools === null || $tools === []) {
            Log::write(ToolListInjector::NO_TOOLS_WARNING);

            return null;
        }
        $list = ToolListInjector::inject($tools, $server->injectionConfig(), $server->injection, $lastPage);
        if ($list->degraded) {
            return null;
        }
        $server->adopt($list);

        return $list->tools;
    }

    /**
     * The cursor of the next page: a non-empty string. Anything else ends the listing, as `while (cursor)` does in the
     * TypeScript client. `'0'` is a cursor.
     */
    public static function nextCursor(mixed $cursor): ?string
    {
        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    /**
     * TS ensureRegistries (D17: no cache), reading the pages from the first (D66, B10 fixed). It runs on a call whose
     * tool no listing of this server named, until one rebuild has read every page; under PHP-FPM that is every call,
     * as before. It stops at the first page that names the tool (Plan F spec conflict 3), so a tool of page 1 costs one
     * page, as before; a tool that no page names reads every page. A get_more_tools call rebuilds too: a customer's
     * tool of that name is found on its page, so its own parameters stay its own; AgentCat's own is on no page, and the
     * read to the last page gives it its exact entry. A page that cannot be read ends the rebuild: the pages before it
     * are kept, the other tools keep the heuristic strip, and the next call for one of them tries again. A cursor
     * already followed ends the listing. Synchronous PHP cannot stop a page that hangs: the budget only stops the next
     * page.
     *
     * @param \Closure(?string): (array{list<mixed>, ?string}|null) $listPage the library's own page at a cursor (null
     *                                                                       for the first): its tools and its next
     *                                                                       cursor, or null when the answer has no
     *                                                                       tool list; may throw
     * @param (\Closure(): float)|null $clock seconds on a monotonic clock; null reads `hrtime()`
     */
    public static function ensureRegistries(TrackedServer $server, string $toolName, \Closure $listPage, ?\Closure $clock = null): void
    {
        if ($server->everyPageRead || ($server->registry !== null && array_key_exists($toolName, $server->registry))) {
            return;
        }
        try {
            $read = self::readPages($toolName, $listPage, $clock ?? static fn(): float => hrtime(true) / 1e9);
            if ($read === null) {
                return;
            }
            [$tools, $lastPage, $stopped] = $read;
            $list = ToolListInjector::inject($tools, $server->injectionConfig(), $server->injection, $lastPage);
            if ($list->degraded) {
                return;
            }
            $server->adopt($list);
            if ($stopped !== null) {
                Log::write($stopped);

                return;
            }
            $server->everyPageRead = $lastPage;
            Log::write(self::REBUILT);
        } catch (\Throwable $e) {
            Log::write(self::REBUILD_FAILED . ErrorText::of($e));
        }
    }

    /**
     * Reads the pages from the first one, following each next cursor, up to the first page that names the tool. Null
     * when the first page has no tool list (nothing is logged, as before); what listing the first page throws passes.
     *
     * @param \Closure(?string): (array{list<mixed>, ?string}|null) $listPage
     * @param \Closure(): float $clock
     * @return array{list<mixed>, bool, ?string}|null every tool read, whether the last page was read, and the line that
     *                                               says why the read stopped before the tool's page and the last page
     *                                               (null when it reached one of them)
     */
    private static function readPages(string $toolName, \Closure $listPage, \Closure $clock): ?array
    {
        $deadline = $clock() + self::REBUILD_BUDGET_SECONDS;
        $tools = [];
        $followed = []; // the cursors followed, as keys and values
        $cursor = null;
        $read = 0; // pages read
        while (true) {
            try {
                $page = $listPage($cursor);
            } catch (\Throwable $e) {
                if ($read === 0) {
                    throw $e;
                }

                return [$tools, false, sprintf(self::REBUILD_STOPPED, $read, ErrorText::of($e))];
            }
            if ($page === null) {
                return $read === 0 ? null : [$tools, false, sprintf(self::REBUILD_STOPPED, $read, sprintf('page %d has no tool list', $read + 1))];
            }
            ++$read;
            [$listed, $cursor] = $page;
            $named = false;
            foreach ($listed as $tool) {
                $tools[] = $tool;
                $named = $named || self::names($tool, $toolName);
            }
            if ($cursor === null || isset($followed[$cursor])) {
                return [$tools, true, null]; // the last page, or a cursor back to a page already read
            }
            if ($named) {
                return [$tools, false, null];
            }
            $followed[$cursor] = $cursor;
            if ($read === self::REBUILD_MAX_PAGES) {
                return [$tools, false, sprintf(self::REBUILD_STOPPED, $read, sprintf('the limit is %d pages', self::REBUILD_MAX_PAGES))];
            }
            if ($clock() >= $deadline) {
                return [$tools, false, sprintf(self::REBUILD_STOPPED, $read, sprintf('the budget of %s s is used up', self::REBUILD_BUDGET_SECONDS))];
            }
        }
    }

    /** The tool is named `$name`. A JSON tree's members are read without accessors, so this never throws. */
    private static function names(mixed $tool, string $name): bool
    {
        return $tool instanceof \stdClass && (get_object_vars($tool)['name'] ?? null) === $name;
    }
}
