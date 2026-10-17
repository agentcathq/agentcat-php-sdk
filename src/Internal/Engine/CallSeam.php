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
     * The result with its tool-result tree replaced by a decorated one.
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
     * The `tools` of the library's own first `tools/list` page, or null when there is none. May throw.
     *
     * @return list<mixed>|null
     */
    public function listOriginal(): ?array;
}
