<?php

declare(strict_types=1);

namespace AgentCat\Internal\ServerInfo;

use AgentCat\Internal\Engine\InjectionConfig;
use AgentCat\Internal\Engine\InjectionState;
use AgentCat\Internal\Engine\ToolListInjector;
use AgentCat\Internal\Support\ErrorText;

/**
 * Reads a server's lists, page by page, into the body of a heartbeat. The tool list must be read whole, or there is
 * no body; another list that cannot be read is left out. PHP cannot stop a handler that hangs: the limits stop only
 * the next page.
 *
 * @internal
 */
final class BodyBuilder
{
    /** A list asks for no further page after this many seconds. */
    public const float LIST_SECONDS = 5.0;

    /** A list has at most this many pages. */
    public const int MAX_PAGES = 100;

    /**
     * @param InjectionConfig   $injection what AgentCat adds to the tool list a client gets
     * @param \Closure(): float $clock     seconds on a monotonic clock
     * @param \Closure(): void  $check     runs before each page; throws `Skipped` when the build has no time left
     *
     * @throws Skipped    when the tool list cannot be read, or `$check` stops the build
     * @throws \Throwable what reading the title or the instructions throws
     */
    public static function build(string $projectId, string $serverName, ?string $serverVersion, ServerInfoSource $source, InjectionConfig $injection, \Closure $clock, \Closure $check): ServerInfoBody
    {
        $features = [];
        $failed = [];
        foreach (ServerInfoSource::LISTS as $list) {
            try {
                $items = self::read($source, $list, $clock, $check);
            } catch (Skipped $skipped) {
                throw $skipped;
            } catch (\Throwable $e) {
                $reason = $e instanceof \UnexpectedValueException ? $e->getMessage() : ErrorText::of($e);
                if ($list === ServerInfoSource::TOOLS) {
                    throw Skipped::after('the tool list failed - ' . $reason, $e);
                }
                $failed[$list] = $reason;

                continue;
            }
            if ($items !== null) {
                $features[$list] = $items;
            } elseif ($list === ServerInfoSource::TOOLS) {
                throw new Skipped('the server has no tool list');
            }
        }
        // The tool list as a client gets it: an empty one is served as it is, and get_more_tools comes once, at the end.
        if (!$source->toolsAdvertised() && $features[ServerInfoSource::TOOLS] !== []) {
            $features[ServerInfoSource::TOOLS] = ToolListInjector::inject($features[ServerInfoSource::TOOLS], $injection, new InjectionState())->tools;
        }

        return new ServerInfoBody($projectId, $serverName, $serverVersion, $source->title(), $source->instructions(), $features, $failed);
    }

    /**
     * Every item of a list, or null when the server has no handler for it.
     *
     * @param \Closure(): float $clock
     * @param \Closure(): void  $check
     *
     * @return list<mixed>|null
     *
     * @throws \UnexpectedValueException when the list breaks a limit; its message is the reason
     * @throws \Throwable                what the handler throws
     */
    private static function read(ServerInfoSource $source, string $list, \Closure $clock, \Closure $check): ?array
    {
        $deadline = $clock() + self::LIST_SECONDS;
        $items = [];
        $followed = [];
        $cursor = null;
        for ($read = 1; true; ++$read) {
            $check();
            $page = $source->page($list, $cursor);
            if ($page === null) {
                return $read === 1 ? null : throw new \UnexpectedValueException(sprintf('page %d has no list', $read));
            }
            [$listed, $cursor] = $page;
            foreach ($listed as $item) {
                $items[] = $item;
            }
            if ($cursor === null) {
                return $items;
            }
            if (isset($followed[$cursor])) {
                throw new \UnexpectedValueException(sprintf('the cursor "%s" came back after page %d', $cursor, $read));
            }
            $followed[$cursor] = true;
            if ($read === self::MAX_PAGES) {
                throw new \UnexpectedValueException(sprintf('it has more than %d pages', self::MAX_PAGES));
            }
            if ($clock() > $deadline) {
                throw new \UnexpectedValueException(sprintf('it took more than %s s', self::LIST_SECONDS));
            }
        }
    }
}
