<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

/**
 * The exception a tool threw, kept for the request it ran for. mcp/sdk catches tool exceptions and answers with an
 * error result or a JSON-RPC error; the event needs the exception itself (05 E5). Entries go away with their request.
 *
 * @internal
 */
final class ThrowableStash
{
    /** @var \WeakMap<object, \Throwable> */
    private \WeakMap $errors;

    public function __construct()
    {
        $this->errors = new \WeakMap();
    }

    public function put(object $request, \Throwable $error): void
    {
        $this->errors[$request] = $error;
    }

    /** The exception kept for the request, which is then forgotten; null when there is none. */
    public function take(?object $request): ?\Throwable
    {
        if ($request === null || !isset($this->errors[$request])) {
            return null;
        }
        $error = $this->errors[$request];
        unset($this->errors[$request]);

        return $error;
    }
}
