<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

/**
 * What `CallInterceptor` needs from an MCP library for one `tools/call` request. Each adapter implements it over its
 * own request and result types; `TResult` is the library's answer to the call.
 *
 * @template TResult
 *
 * @internal
 */
interface CallSeam
{
    /** The called tool's name; '' when the request names none. */
    public function toolName(): string;

    /** The decoded `params.arguments` (objects are `\stdClass`). Never throws. */
    public function arguments(): mixed;

    /** The request data the event records. May throw: the call then runs untraced. */
    public function describe(): CallContext;

    /**
     * Runs the library's handler on the request as received.
     *
     * @return TResult
     */
    public function callOriginal(): mixed;

    /**
     * Runs the library's handler on a copy of the request without these top-level arguments.
     *
     * @param list<string> $names
     *
     * @return TResult
     */
    public function callStripped(array $names): mixed;

    /**
     * The tool-result tree of a result, or the message of the JSON-RPC error it carries, and the tool's own
     * exception when the library caught one.
     *
     * @param TResult $result
     */
    public function observe(mixed $result): Observed;

    /**
     * The result with its tool-result tree replaced by a decorated one. Returns a new result and never changes
     * `$result` in place: when publishing fails after `replace()`, the interceptor returns `$result` untouched.
     *
     * @param TResult $result
     *
     * @return TResult
     */
    public function replace(mixed $result, \stdClass $tree): mixed;

    /**
     * A successful answer with this tool-result tree, given without running the library's handler.
     *
     * @return TResult
     */
    public function answer(\stdClass $tree): mixed;

    /**
     * One page of the library's own `tools/list`: the tools of the page at `$cursor` (null for the first) and its next
     * cursor (`ToolListing::nextCursor()`; null on the last page), or null when the answer has no tool list. May throw.
     *
     * @return array{list<mixed>, ?string}|null
     */
    public function listOriginal(?string $cursor): ?array;

    /**
     * For a result that completes later (a stream), a result that calls `$complete` with the final result, and yields
     * what it returns in its place, or calls `$fail` with what the stream throws; null for an ordinary result. Never
     * throws.
     *
     * @param TResult $result
     * @param \Closure(TResult): TResult $complete
     * @param \Closure(\Throwable): void $fail
     *
     * @return TResult|null
     */
    public function later(mixed $result, \Closure $complete, \Closure $fail): mixed;
}
