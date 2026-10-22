<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

use AgentCat\LaravelMcp\LaravelMcpLibrary;
use AgentCat\McpSdk\McpSdkLibrary;

/**
 * The MCP libraries `track()` can instrument, in the order it asks them. Asking loads no library class: each
 * `supports()` is an `instanceof` check.
 *
 * @internal
 */
final class Libraries
{
    /** @return list<Library> */
    public static function all(): array
    {
        return [new McpSdkLibrary(), new LaravelMcpLibrary()];
    }

    public static function for(object $server): ?Library
    {
        foreach (self::all() as $library) {
            if ($library->supports($server)) {
                return $library;
            }
        }

        return null;
    }

    /** Every library's shape signals for the object, comma-joined (TS `fingerprintServerShape`); '' when none applies. */
    public static function fingerprint(object $server): string
    {
        $signals = [];
        foreach (self::all() as $library) {
            array_push($signals, ...$library->signals($server));
        }

        return implode(',', $signals);
    }

    public static function supportMatrix(): string
    {
        return implode(' ', array_map(static fn(Library $library): string => $library->supportMatrix(), self::all()));
    }

    /** What to pass instead of an object that no library can map to a tracked server; null when there is no advice. */
    public static function untrackedAdvice(object $server): ?string
    {
        return McpSdkLibrary::untrackedAdvice($server);
    }

    /**
     * The library that tracks the object through the object it was made from, as mcp/sdk tracks the Server that a
     * tracked Builder built; null when no library does. Never throws.
     */
    public static function trackerOf(object $server): ?Library
    {
        foreach (self::all() as $library) {
            if ($library->trackedServer($server) instanceof TrackedServer) {
                return $library;
            }
        }

        return null;
    }

    public static function trackedServer(object $server): ?TrackedServer
    {
        foreach (self::all() as $library) {
            $tracked = $library->trackedServer($server);
            if ($tracked instanceof TrackedServer) {
                return $tracked;
            }
        }

        return null;
    }
}
