<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\LaravelMcp;

use AgentCat\Internal\Engine\ThrowableStash;
use AgentCat\LaravelMcp\Internal\HandshakeClient;
use AgentCat\LaravelMcp\Internal\LaravelCallSeam;
use AgentCat\LaravelMcp\Internal\LaravelRequestReader;
use AgentCat\Tests\Support\LaravelMcp\ConformanceServer;
use AgentCat\Tests\Support\LaravelMcp\LineTransport;
use AgentCat\Tests\Support\Thrown;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use PHPUnit\Framework\TestCase;

final class LaravelCallSeamTest extends TestCase
{
    private ServerContext $context;

    /** @var list<JsonRpcRequest> the requests the delegate answered, in order */
    private array $sent = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->context = (new ConformanceServer(new LineTransport()))->createContext();
    }

    public function testTheNameAndArgumentsComeFromTheParams(): void
    {
        $seam = $this->seam(['name' => 'echo', 'arguments' => ['msg' => 'hi', 3 => 'three']]);
        $odd = $this->seam(['name' => 7, 'arguments' => 'x']);

        $this->assertSame('echo', $seam->toolName());
        $this->assertSame('{"msg":"hi","3":"three"}', json_encode($seam->arguments()));
        $this->assertSame('', $odd->toolName());
        $this->assertEquals(new \stdClass(), $odd->arguments());
    }

    public function testTheOriginalCallSendsTheRequestItself(): void
    {
        $request = new JsonRpcRequest(5, 'tools/call', ['name' => 'echo', 'arguments' => ['msg' => 'hi', 'context' => 'why']]);

        $this->seamFor($request)->callOriginal();

        $this->assertSame([$request], $this->sent);
    }

    public function testTheStrippedCallSendsACopyWithoutTheNames(): void
    {
        $params = ['name' => 'echo', 'arguments' => ['msg' => 'hi', 'context' => 'why', 'session_id' => 'start'], '_meta' => ['progressToken' => 'p']];
        $request = new JsonRpcRequest('r5', 'tools/call', $params);

        $this->seamFor($request)->callStripped(['context', 'session_id']);

        $sent = $this->sent[0] ?? null;
        $this->assertInstanceOf(JsonRpcRequest::class, $sent);
        $this->assertNotSame($request, $sent);
        $this->assertSame('r5', $sent->id);
        $this->assertSame('tools/call', $sent->method);
        $this->assertSame(['name' => 'echo', 'arguments' => ['msg' => 'hi'], '_meta' => ['progressToken' => 'p']], $sent->params);
        $this->assertSame($params, $request->params, 'the request as received stays untouched');
    }

    public function testAStrippedCallWithoutArgumentsSendsNone(): void
    {
        $this->seam(['name' => 'echo'])->callStripped(['context']);

        $this->assertSame(['name' => 'echo'], ($this->sent[0] ?? null)?->params);
    }

    public function testAResultIsObservedAsItsTreeWithTheToolsException(): void
    {
        $stash = new ThrowableStash();
        $error = new \RuntimeException('tool boom');
        $seam = $this->seam(['name' => 'echo'], static function (JsonRpcRequest $request) use ($stash, $error): JsonRpcResponse {
            $stash->put($request, $error);

            return JsonRpcResponse::result($request->id, ['content' => [], 'isError' => true]);
        }, stash: $stash);

        $observed = $seam->observe($seam->callStripped([]));

        $this->assertSame('{"content":[],"isError":true}', json_encode($observed->tree));
        $this->assertNull($observed->protocolError);
        $this->assertSame($error, $observed->thrown);
        $this->assertNull($seam->observe(JsonRpcResponse::result(5, ['content' => []]))->thrown, 'taken once');
    }

    public function testAJsonRpcErrorIsObservedAsItsMessage(): void
    {
        $seam = $this->seam(['name' => 'echo']);

        $this->assertSame('bad', $seam->observe(JsonRpcResponse::error(5, -32602, 'bad'))->protocolError);
        $this->assertSame('', $seam->observe(new JsonRpcResponse(['id' => 5, 'error' => ['code' => 1]]))->protocolError);
        $this->assertNull($seam->observe(JsonRpcResponse::error(5, -32602, 'bad'))->tree);
    }

    public function testAStreamIsObservedWithoutATree(): void
    {
        $observed = $this->seam(['name' => 'echo'])->observe([JsonRpcResponse::result(5, ['content' => []])]);

        $this->assertNull($observed->tree);
        $this->assertNull($observed->protocolError);
        $this->assertNull($observed->thrown);
    }

    public function testReplaceSwapsTheResultOnly(): void
    {
        $seam = $this->seam(['name' => 'echo']);
        $result = new JsonRpcResponse(['id' => 5, 'result' => ['content' => []], 'extra' => 'kept']);

        $replaced = $seam->replace($result, (object) ['content' => [(object) ['type' => 'text', 'text' => 'x']], '_meta' => (object) ['k' => 'v']]);

        $this->assertInstanceOf(JsonRpcResponse::class, $replaced);
        $this->assertNotSame($result, $replaced);
        $this->assertSame(['id' => 5, 'result' => ['content' => [['type' => 'text', 'text' => 'x']], '_meta' => ['k' => 'v']], 'extra' => 'kept'], $replaced->content);
        $this->assertSame(['id' => 5, 'result' => ['content' => []], 'extra' => 'kept'], $result->content);
    }

    public function testReplaceLeavesAnErrorAndAStreamAlone(): void
    {
        $seam = $this->seam(['name' => 'echo']);
        $error = JsonRpcResponse::error(5, -32602, 'bad');
        $stream = [JsonRpcResponse::result(5, ['content' => []])];

        $this->assertSame($error, $seam->replace($error, new \stdClass()));
        $this->assertSame($stream, $seam->replace($stream, new \stdClass()));
    }

    public function testAnAnswerIsAResultForTheRequest(): void
    {
        $answer = $this->seam(['name' => 'get_more_tools'])->answer((object) ['content' => [(object) ['type' => 'text', 'text' => 'x']]]);

        $this->assertSame(['id' => 5, 'result' => ['content' => [['type' => 'text', 'text' => 'x']]]], $answer->content);
        $this->assertSame([], $this->sent);
    }

    /** D66 (B10 fixed): the rebuild lists the page at a cursor, and reads the page's next cursor. */
    public function testTheRebuildListsAPageAtACursorWithTheListHandler(): void
    {
        $listed = [];
        $seam = $this->seam(['name' => 'echo'], list: static function (JsonRpcRequest $request) use (&$listed): JsonRpcResponse {
            $listed[] = $request;

            return JsonRpcResponse::result($request->id, ['tools' => [['name' => 'echo']], 'nextCursor' => 'p2']);
        });

        $this->assertEquals([[(object) ['name' => 'echo']], 'p2'], $seam->listOriginal(null));
        $this->assertEquals([[(object) ['name' => 'echo']], 'p2'], $seam->listOriginal('p2'));
        $this->assertCount(2, $listed);
        $this->assertSame(['agentcat-rebuild', 'tools/list', []], [$listed[0]->id, $listed[0]->method, $listed[0]->params]);
        $this->assertSame(['agentcat-rebuild', 'tools/list', ['cursor' => 'p2']], [$listed[1]->id, $listed[1]->method, $listed[1]->params]);
        $last = $this->seam(['name' => 'echo'], list: static fn(JsonRpcRequest $request): JsonRpcResponse => JsonRpcResponse::result($request->id, ['tools' => [], 'nextCursor' => '']));
        $this->assertSame([[], null], $last->listOriginal(null), 'an empty cursor ends the listing');
        $this->assertNull($this->seam(['name' => 'echo'])->listOriginal(null), 'no list handler');
        $this->assertNull($this->seam(['name' => 'echo'], list: static fn(JsonRpcRequest $request): array => [JsonRpcResponse::result($request->id, ['tools' => []])])->listOriginal(null), 'a streamed listing');
        $this->assertNull($this->seam(['name' => 'echo'], list: static fn(JsonRpcRequest $request): JsonRpcResponse => JsonRpcResponse::error($request->id, -32603, 'down'))->listOriginal(null), 'a JSON-RPC error');
    }

    public function testAnOrdinaryResultDoesNotCompleteLater(): void
    {
        $this->assertNull($this->seam(['name' => 'echo'])->later(JsonRpcResponse::result(5, []), $this->unexpected(...), $this->unexpected(...)));
    }

    public function testAStreamCompletesWithItsFirstAnswerAndPassesTheRest(): void
    {
        $completed = new \ArrayObject();
        $first = JsonRpcResponse::result(5, ['content' => []]);
        $second = JsonRpcResponse::result(5, ['content' => [['type' => 'text', 'text' => 'again']]]);
        $decorated = JsonRpcResponse::result(5, ['content' => [['type' => 'text', 'text' => 'decorated']]]);
        $note = JsonRpcResponse::notification('notifications/progress', ['progress' => 1]);
        $complete = static function (mixed $final) use ($completed, $decorated): JsonRpcResponse {
            $completed->append($final);

            return $decorated;
        };

        $stream = $this->seam(['name' => 'echo'])->later([$note, $first, $note, $second], $complete, $this->unexpected(...));

        $this->assertInstanceOf(\Generator::class, $stream);
        $this->assertSame([], $completed->getArrayCopy(), 'nothing completes before the stream runs');
        $this->assertSame([0 => $note, 1 => $decorated, 2 => $note, 3 => $second], iterator_to_array($stream));
        $this->assertSame([$first], $completed->getArrayCopy());
    }

    public function testAnErrorAnswerCompletesAStreamToo(): void
    {
        $completed = new \ArrayObject();
        $error = JsonRpcResponse::error(5, -32602, 'bad');
        $complete = static function (mixed $final) use ($completed): mixed {
            $completed->append($final);

            return $final;
        };

        $stream = $this->seam(['name' => 'echo'])->later([$error], $complete, $this->unexpected(...));

        $this->assertInstanceOf(\Generator::class, $stream);
        $this->assertSame([$error], iterator_to_array($stream));
        $this->assertSame([$error], $completed->getArrayCopy());
    }

    public function testACompletionThatGivesAStreamIsSentMessageByMessage(): void
    {
        $note = JsonRpcResponse::notification('notifications/progress', ['progress' => 1]);
        $final = JsonRpcResponse::result(5, ['content' => []]);

        $stream = $this->seam(['name' => 'echo'])->later([$note, $final], static fn(): array => [$final, $note], $this->unexpected(...));

        $this->assertInstanceOf(\Generator::class, $stream);
        $this->assertSame([0 => $note, 1 => $final, 2 => $note], iterator_to_array($stream));
    }

    public function testAStreamThatThrowsBeforeItsAnswerFailsThenRethrows(): void
    {
        $failures = new \ArrayObject();
        $broke = new \DomainException('stream broke');
        $fail = static function (\Throwable $e) use ($failures): void {
            $failures->append($e);
        };
        $source = (static function () use ($broke): \Generator {
            yield JsonRpcResponse::notification('notifications/progress', ['progress' => 1]);

            throw $broke;
        })();

        $stream = $this->seam(['name' => 'echo'])->later($source, $this->unexpected(...), $fail);

        $this->assertInstanceOf(\Generator::class, $stream);
        $this->assertSame($broke, Thrown::by(\DomainException::class, static fn(): array => iterator_to_array($stream)));
        $this->assertSame([$broke], $failures->getArrayCopy());
    }

    public function testAStreamThatThrowsAfterItsAnswerDoesNotFailTheCall(): void
    {
        $broke = new \DomainException('after');
        $source = (static function () use ($broke): \Generator {
            yield JsonRpcResponse::result(5, ['content' => []]);

            throw $broke;
        })();

        $stream = $this->seam(['name' => 'echo'])->later($source, static fn(mixed $final): mixed => $final, $this->unexpected(...));

        $this->assertInstanceOf(\Generator::class, $stream);
        $this->assertSame($broke, Thrown::by(\DomainException::class, static fn(): array => iterator_to_array($stream)));
    }

    private function unexpected(): never
    {
        self::fail('not expected to run');
    }

    /**
     * @param array<string, mixed> $params
     * @param (\Closure(JsonRpcRequest): (iterable<JsonRpcResponse>|JsonRpcResponse))|null $answer
     * @param (\Closure(JsonRpcRequest): (iterable<JsonRpcResponse>|JsonRpcResponse))|null $list
     */
    private function seam(array $params, ?\Closure $answer = null, ?\Closure $list = null, ThrowableStash $stash = new ThrowableStash()): LaravelCallSeam
    {
        return $this->seamFor(new JsonRpcRequest(5, 'tools/call', $params), $answer, $list, $stash);
    }

    /**
     * @param (\Closure(JsonRpcRequest): (iterable<JsonRpcResponse>|JsonRpcResponse))|null $answer
     * @param (\Closure(JsonRpcRequest): (iterable<JsonRpcResponse>|JsonRpcResponse))|null $list
     */
    private function seamFor(JsonRpcRequest $request, ?\Closure $answer = null, ?\Closure $list = null, ThrowableStash $stash = new ThrowableStash()): LaravelCallSeam
    {
        $delegate = function (JsonRpcRequest $sent) use ($answer): iterable|JsonRpcResponse {
            $this->sent[] = $sent;

            return $answer instanceof \Closure ? $answer($sent) : JsonRpcResponse::result($sent->id, ['content' => []]);
        };

        return new LaravelCallSeam($request, $this->context, $delegate, $list, $stash, new LaravelRequestReader(static fn(): null => null, new HandshakeClient()));
    }
}
