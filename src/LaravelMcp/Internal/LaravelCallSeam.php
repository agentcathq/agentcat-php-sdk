<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp\Internal;

use AgentCat\Internal\Engine\CallContext;
use AgentCat\Internal\Engine\CallSeam;
use AgentCat\Internal\Engine\Observed;
use AgentCat\Internal\Engine\ThrowableStash;
use AgentCat\Internal\Engine\ToolListing;
use AgentCat\Internal\Json\JsonTree;
use Illuminate\Container\Container;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * One laravel/mcp `tools/call` for `CallInterceptor`. The library's answer is a `JsonRpcResponse`, or a generator of
 * them for a streamed tool.
 *
 * @implements CallSeam<iterable<JsonRpcResponse>|JsonRpcResponse>
 *
 * @internal
 */
final class LaravelCallSeam implements CallSeam
{
    /** The request sent to the library by `callStripped()`: the key of the stashed tool exception. */
    private ?JsonRpcRequest $sent = null;

    /**
     * @param \Closure(JsonRpcRequest): (iterable<JsonRpcResponse>|JsonRpcResponse) $delegate answers a request with
     *                                                                                        the handler the server's
     *                                                                                        entry named before
     *                                                                                        AgentCat's, built at the
     *                                                                                        call, after the strip
     * @param (\Closure(JsonRpcRequest): (iterable<JsonRpcResponse>|JsonRpcResponse))|null $listDelegate the same for
     *                                                                                                   the server's
     *                                                                                                   `tools/list`
     *                                                                                                   entry; null
     *                                                                                                   when it names
     *                                                                                                   no method class
     */
    public function __construct(
        private readonly JsonRpcRequest $request,
        private readonly ServerContext $context,
        private readonly \Closure $delegate,
        private readonly ?\Closure $listDelegate,
        private readonly ThrowableStash $stash,
        private readonly LaravelRequestReader $reader,
    ) {}

    #[\Override]
    public function toolName(): string
    {
        return LaravelRequestReader::toolName($this->request);
    }

    #[\Override]
    public function arguments(): mixed
    {
        return JsonTree::object(LaravelRequestReader::arguments($this->request));
    }

    #[\Override]
    public function describe(): CallContext
    {
        return $this->reader->read($this->request, $this->context);
    }

    #[\Override]
    public function callOriginal(): iterable|JsonRpcResponse
    {
        return ($this->delegate)($this->request);
    }

    /**
     * Sends a copy of the request without the names, and removes them from the `mcp.request` that laravel/mcp bound
     * for this call: the tool's own `Request`, and any other the container builds now, copy its arguments (ac-05-E5).
     * The handler is built after that, so it sees the request without the names.
     *
     * @param list<string> $names
     */
    #[\Override]
    public function callStripped(array $names): iterable|JsonRpcResponse
    {
        $params = $this->request->params;
        $arguments = LaravelRequestReader::arguments($this->request);
        foreach ($names as $name) {
            unset($arguments[$name]);
        }
        if (is_array($params['arguments'] ?? null)) {
            $params['arguments'] = $arguments;
        }
        $this->sent = new JsonRpcRequest($this->request->id, $this->request->method, $params);
        $container = Container::getInstance();
        $bound = $container->bound('mcp.request') ? $container->make('mcp.request') : null;
        if ($bound instanceof McpRequest) {
            /** @var array<string, mixed> $arguments JSON member names; PHP keeps a numeric one as an int key */
            $bound->setArguments($arguments);
        }

        return ($this->delegate)($this->sent);
    }

    /** @param iterable<JsonRpcResponse>|JsonRpcResponse $result */
    #[\Override]
    public function observe(mixed $result): Observed
    {
        $thrown = $this->stash->take($this->sent);
        if (!$result instanceof JsonRpcResponse) {
            return new Observed(thrown: $thrown);
        }
        $error = $result->content['error'] ?? null;

        return is_array($error)
            ? new Observed(protocolError: is_string($error['message'] ?? null) ? $error['message'] : '', thrown: $thrown)
            : new Observed(LaravelTrees::result($result), thrown: $thrown);
    }

    /**
     * @param iterable<JsonRpcResponse>|JsonRpcResponse $result
     *
     * @return iterable<JsonRpcResponse>|JsonRpcResponse
     */
    #[\Override]
    public function replace(mixed $result, \stdClass $tree): iterable|JsonRpcResponse
    {
        // A JSON-RPC error is never decorated: it reaches the client as the library built it.
        return $result instanceof JsonRpcResponse && isset($result->content['result'])
            ? new JsonRpcResponse([...$result->content, 'result' => LaravelTrees::nativeResult($tree)])
            : $result;
    }

    #[\Override]
    public function answer(\stdClass $tree): JsonRpcResponse
    {
        return JsonRpcResponse::result($this->request->id, LaravelTrees::nativeResult($tree));
    }

    #[\Override]
    public function listOriginal(?string $cursor): ?array
    {
        if (!$this->listDelegate instanceof \Closure) {
            return null;
        }
        $answer = ($this->listDelegate)(new JsonRpcRequest('agentcat-rebuild', 'tools/list', $cursor === null ? [] : ['cursor' => $cursor]));
        $tools = LaravelTrees::tools($answer);
        if ($tools === null || !$answer instanceof JsonRpcResponse) {
            return null;
        }
        $result = $answer->content['result'] ?? null;

        return [$tools, ToolListing::nextCursor(is_array($result) ? ($result['nextCursor'] ?? null) : null)];
    }

    /**
     * A streamed answer (a generator, or another iterable of messages) passes message by message. Its first result or
     * JSON-RPC error completes the call and goes out as `$complete` returns it; what the stream throws before that
     * fails the call, then passes on. A stream the client abandons completes nothing: no event.
     *
     * @param iterable<JsonRpcResponse>|JsonRpcResponse $result
     * @param \Closure(iterable<JsonRpcResponse>|JsonRpcResponse): (iterable<JsonRpcResponse>|JsonRpcResponse) $complete
     * @param \Closure(\Throwable): void $fail
     *
     * @return \Generator<int, JsonRpcResponse>|null
     */
    #[\Override]
    public function later(mixed $result, \Closure $complete, \Closure $fail): ?\Generator
    {
        if ($result instanceof JsonRpcResponse) {
            return null;
        }

        return (static function () use ($result, $complete, $fail): \Generator {
            $done = false;
            try {
                foreach ($result as $message) {
                    if ($done || !self::answers($message)) {
                        yield $message;

                        continue;
                    }
                    $done = true;
                    foreach (self::messages($complete($message)) as $answer) {
                        yield $answer; // keys stay 0, 1, …: laravel/mcp's test helpers keep them
                    }
                }
            } catch (\Throwable $e) {
                if (!$done) {
                    $fail($e);
                }

                throw $e;
            }
        })();
    }

    /** A message that answers the request (a result or a JSON-RPC error), not a notification. */
    private static function answers(mixed $message): bool
    {
        return $message instanceof JsonRpcResponse && (array_key_exists('result', $message->content) || array_key_exists('error', $message->content));
    }

    /**
     * @param iterable<JsonRpcResponse>|JsonRpcResponse $answer
     *
     * @return iterable<JsonRpcResponse>
     */
    private static function messages(iterable|JsonRpcResponse $answer): iterable
    {
        return $answer instanceof JsonRpcResponse ? [$answer] : $answer;
    }
}
