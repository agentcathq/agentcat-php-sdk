<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\LaravelMcp;

use AgentCat\Internal\ServerInfo\ListFailure;
use AgentCat\Internal\ServerInfo\ServerInfoSource;
use AgentCat\LaravelMcp\Internal\LaravelServerInfo;
use AgentCat\Tests\Support\LaravelMcp\LaravelTestCase;
use AgentCat\Tests\Support\LaravelMcp\RecordingMethod;
use AgentCat\Tests\Support\Thrown;
use Illuminate\Container\Container;
use Laravel\Mcp\Schema\Implementation;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/** What `LaravelServerInfo` makes of the answers of a laravel/mcp list handler. */
final class LaravelServerInfoTest extends LaravelTestCase
{
    public function testEachListAsksTheHandlerOfItsMethodWithTheCursor(): void
    {
        $handler = $this->handler(static fn(JsonRpcRequest $request): JsonRpcResponse => JsonRpcResponse::result($request->id, [
            'tools' => [['name' => 't']],
            'resources' => [['uri' => 'file:///r']],
            'resourceTemplates' => [],
            'prompts' => [['name' => 'p']],
            'nextCursor' => $request->get('cursor') === null ? 'next' : '',
        ]));
        $info = new LaravelServerInfo(['tools/list' => 'handler', 'resources/list' => 'handler', 'resources/templates/list' => 'handler', 'prompts/list' => 'handler'], $this->context());

        $this->assertEquals([[(object) ['name' => 't']], 'next'], $info->page(ServerInfoSource::TOOLS, null));
        $this->assertEquals([[(object) ['uri' => 'file:///r']], null], $info->page(ServerInfoSource::RESOURCES, 'c1'), 'an empty cursor ends the list');
        $this->assertSame([[], null], $info->page(ServerInfoSource::RESOURCE_TEMPLATES, 'c2'));
        $this->assertEquals([[(object) ['name' => 'p']], null], $info->page(ServerInfoSource::PROMPTS, 'c3'));

        $this->assertSame(
            [['tools/list', []], ['resources/list', ['cursor' => 'c1']], ['resources/templates/list', ['cursor' => 'c2']], ['prompts/list', ['cursor' => 'c3']]],
            array_map(static fn(JsonRpcRequest $request): array => [$request->method, $request->params], $handler->requests),
        );
        $this->assertSame(['agentcat-server-info'], array_values(array_unique(array_map(static fn(JsonRpcRequest $request): mixed => $request->id, $handler->requests))));
        $this->assertFalse($info->toolsAdvertised());
    }

    public function testAnEntryThatNamesNoHandlerHasNoPage(): void
    {
        $info = new LaravelServerInfo(['tools/list' => null, 'prompts/list' => 42], $this->context());

        $this->assertNull($info->page(ServerInfoSource::TOOLS, null));
        $this->assertNull($info->page(ServerInfoSource::RESOURCES, null));
        $this->assertNull($info->page(ServerInfoSource::PROMPTS, null));
    }

    public function testAStreamAJsonRpcErrorOrAnAnswerWithoutTheListFailsTheList(): void
    {
        $answers = [
            'the handler answered with a stream' => static fn(JsonRpcRequest $request): array => [JsonRpcResponse::result($request->id, ['resources' => []])],
            'the handler answered with a JSON-RPC error' => static fn(JsonRpcRequest $request): JsonRpcResponse => JsonRpcResponse::error($request->id, -32602, 'a secret cursor'),
            'the answer of the handler has no list' => static fn(JsonRpcRequest $request): JsonRpcResponse => JsonRpcResponse::result($request->id, ['prompts' => []]),
        ];
        foreach ($answers as $message => $answer) {
            $this->handler($answer);
            $info = new LaravelServerInfo(['resources/list' => 'handler'], $this->context());

            $error = Thrown::by(ListFailure::class, static fn(): ?array => $info->page(ServerInfoSource::RESOURCES, null));

            $this->assertSame($message, $error->getMessage());
        }
        foreach ([['resources' => ['a' => 1]], ['resources' => 'text']] as $result) {
            $this->handler(static fn(JsonRpcRequest $request): JsonRpcResponse => JsonRpcResponse::result($request->id, $result));
            $info = new LaravelServerInfo(['resources/list' => 'handler'], $this->context());

            $this->assertSame('the answer of the handler has no list', Thrown::by(ListFailure::class, static fn(): ?array => $info->page(ServerInfoSource::RESOURCES, null))->getMessage());
        }
        $this->handler(static fn(): JsonRpcResponse => new JsonRpcResponse(['error' => 'odd']));
        $info = new LaravelServerInfo(['resources/list' => 'handler'], $this->context());
        $this->assertSame('the handler answered with a JSON-RPC error', Thrown::by(ListFailure::class, static fn(): ?array => $info->page(ServerInfoSource::RESOURCES, null))->getMessage());
        $this->handler(static fn(): JsonRpcResponse => new JsonRpcResponse(['result' => 'text']));
        $info = new LaravelServerInfo(['resources/list' => 'handler'], $this->context());
        $this->assertSame('the answer of the handler has no list', Thrown::by(ListFailure::class, static fn(): ?array => $info->page(ServerInfoSource::RESOURCES, null))->getMessage());
    }

    public function testANameThatIsNoListOfAHeartbeatIsRefused(): void
    {
        $info = new LaravelServerInfo([], $this->context());

        $this->assertSame('the list is no list of a heartbeat', Thrown::by(ListFailure::class, static fn(): ?array => $info->page('roots', null))->getMessage());
    }

    public function testTheTitleAndTheInstructionsComeFromTheContextOfTheRequest(): void
    {
        $this->assertSame(['', 'Read me.'], [(new LaravelServerInfo([], $this->context()))->title(), (new LaravelServerInfo([], $this->context()))->instructions()]);
        $titled = new LaravelServerInfo([], $this->context(new Implementation('demo', '1', title: 'The Title')));
        $this->assertSame('The Title', $titled->title());
        $this->assertSame(['demo', '1'], $titled->server());
    }

    /**
     * Binds a recording handler as the container key `handler`.
     *
     * @param \Closure(JsonRpcRequest): (iterable<JsonRpcResponse>|JsonRpcResponse) $answer
     */
    private function handler(\Closure $answer): RecordingMethod
    {
        $handler = new RecordingMethod(answer: $answer);
        Container::getInstance()->instance('handler', $handler);

        return $handler;
    }

    private function context(?Implementation $implementation = null): ServerContext
    {
        return new ServerContext([], [], $implementation ?? new Implementation('demo', '1'), 'Read me.', 50, 15, [], [], []);
    }
}
