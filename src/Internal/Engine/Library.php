<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

use Psr\Log\LoggerInterface;

/**
 * One MCP library that `track()` can instrument. `supports()`, `sdk()`, `supportMatrix()`, `signals()`, `logger()`
 * and `trackedServer()` take any object and never throw; `inspect()`, `serverInfo()` and `install()` run only after
 * `supports()` returned true for the object.
 *
 * @internal
 */
interface Library
{
    /** Whether this library instruments the object. Takes any object; never throws. */
    public function supports(object $server): bool;

    /** The `sdk` field of the setup line, for example `mcp-sdk/builder`. Never throws. */
    public function sdk(): string;

    /** The sentence that says which versions and objects of this library AgentCat supports. Never throws. */
    public function supportMatrix(): string;

    /**
     * The shape of the object, for the setup and compatibility lines. Takes any object; never throws.
     *
     * @return list<string>
     */
    public function signals(object $server): array;

    /** The PSR-3 logger set on the server; null when none is set or it cannot be read. Any object; never throws. */
    public function logger(object $server): ?LoggerInterface;

    /**
     * Checks that AgentCat can instrument the server. Runs only after `supports()`.
     *
     * @throws \UnexpectedValueException with the compatibility message, after logging the compatibility line
     */
    public function inspect(object $server): void;

    /**
     * Reads the server name and version when an event is published. Runs only after `supports()`.
     *
     * @return \Closure(): array{0: ?string, 1: ?string}
     */
    public function serverInfo(object $server): \Closure;

    /** Adds AgentCat's handlers to the server. Runs only after `supports()` and `inspect()`. */
    public function install(object $server, TrackedServer $state, Publisher $publisher, CallInterceptor $interceptor): void;

    /** The tracking state AgentCat keeps in the object; null when it has none. Takes any object; never throws. */
    public function trackedServer(object $server): ?TrackedServer;
}
