<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\LaravelMcp;

use AgentCat\Internal\Engine\ThrowableStash;
use AgentCat\LaravelMcp\Internal\CapturingCallTool;
use AgentCat\Tests\Contract\PrivateMemberManifest;
use AgentCat\Tests\Support\LaravelMcp\ConformanceServer;
use AgentCat\Tests\Support\LaravelMcp\LaravelTestCase;
use AgentCat\Tests\Support\LaravelMcp\LineTransport;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ToolInvoker;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * AgentCat answers laravel/mcp's `tools/call` with `CapturingCallTool`, a copy of `CallTool::handle()` whose invoker
 * overrides `ToolInvoker::toErrorResponse()` to keep the tool's own exception. A future
 * laravel/mcp that makes either parent or method final or private, changes their signatures, or answers differently
 * breaks it.
 */
#[Group('contract')]
final class ExtendedClassesTest extends LaravelTestCase
{
    public function testTheManifestListsEveryLaravelClassAgentCatExtends(): void
    {
        $laravel = static fn(string $class): bool => str_starts_with($class, 'Laravel\\');

        $this->assertSame([CallTool::class, ToolInvoker::class], array_values(array_filter(PrivateMemberManifest::extendedClasses(), $laravel)));
        $this->assertSame([CallTool::class, ToolInvoker::class], array_values(array_filter(array_keys(PrivateMemberManifest::EXTENDED), $laravel)));
    }

    /** @return \Iterator<string, array{class-string, string}> */
    public static function laravelClasses(): \Iterator
    {
        foreach (PrivateMemberManifest::EXTENDED as $class => $signature) {
            if (str_starts_with($class, 'Laravel\\')) {
                yield $class => [$class, $signature];
            }
        }
    }

    /** @param class-string $class */
    #[DataProvider('laravelClasses')]
    public function testTheParentStillHasTheMethodAgentCatOverrides(string $class, string $signature): void
    {
        $parent = new \ReflectionClass($class);
        $name = strstr($signature, '(', true);
        $this->assertIsString($name);

        $this->assertFalse($parent->isFinal(), $class . ' is final');
        $method = $parent->getMethod($name);
        $this->assertFalse($method->isFinal(), $class . '::' . $name . '() is final');
        $this->assertFalse($method->isPrivate());
        $this->assertSame($signature, $this->signature($method));
    }

    public function testTheInvokerCanBeBuiltWithoutArguments(): void
    {
        $constructor = (new \ReflectionClass(ToolInvoker::class))->getConstructor();

        $this->assertTrue($constructor === null || $constructor->getNumberOfRequiredParameters() === 0);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function calls(): iterable
    {
        yield 'a tool' => [['name' => 'echo', 'arguments' => ['msg' => 'hi']]];
        yield 'an unknown tool' => [['name' => 'nope']];
        yield 'no name' => [[]];
        yield 'a null name' => [['name' => null]];
        yield 'a number as the name' => [['name' => 7]];
        yield 'a boolean as the name' => [['name' => true]];
        yield 'a list as the name' => [['name' => ['echo']]];
        yield 'a tool that throws' => [['name' => 'boom']];
        yield 'a tool that refuses' => [['name' => 'refuses']];
        yield 'structured content' => [['name' => 'structured']];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('calls')]
    public function testCapturingCallToolAnswersExactlyLikeCallTool(array $params): void
    {
        $server = $this->app?->make(ConformanceServer::class, ['transport' => new LineTransport()]);
        $this->assertInstanceOf(ConformanceServer::class, $server);
        $context = $server->createContext();
        $request = new JsonRpcRequest(3, 'tools/call', $params);

        $expected = $this->answer(static fn(): mixed => (new CallTool())->handle($request, $context));
        $actual = $this->answer(static fn(): mixed => (new CapturingCallTool(new ThrowableStash()))->handle($request, $context));

        $this->assertSame($expected, $actual);
    }

    public function testCapturingCallToolKeepsTheToolsExceptionForItsRequest(): void
    {
        $server = $this->app?->make(ConformanceServer::class, ['transport' => new LineTransport()]);
        $this->assertInstanceOf(ConformanceServer::class, $server);
        $stash = new ThrowableStash();
        $request = new JsonRpcRequest(3, 'tools/call', ['name' => 'boom']);

        (new CapturingCallTool($stash))->handle($request, $server->createContext());

        $thrown = $stash->take($request);
        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertSame('tool boom', $thrown->getMessage());
    }

    /**
     * The JSON of an answer, or of the JSON-RPC exception it throws, or the class and message of another throwable.
     *
     * @param \Closure(): mixed $handle
     */
    private function answer(\Closure $handle): string
    {
        try {
            $answer = $handle();
        } catch (JsonRpcException $e) {
            $answer = $e->toJsonRpcResponse();
        } catch (\Throwable $e) {
            return $e::class . ': ' . $e->getMessage();
        }

        return $answer instanceof JsonRpcResponse ? $answer->toJson() : get_debug_type($answer);
    }

    /** `name(type $parameter = default, ...): return type`, defaults as JSON. */
    private function signature(\ReflectionMethod $method): string
    {
        $parameters = array_map(static function (\ReflectionParameter $parameter): string {
            $text = $parameter->getType() . ' $' . $parameter->getName();

            return $parameter->isDefaultValueAvailable()
                ? $text . ' = ' . json_encode($parameter->getDefaultValue(), JSON_THROW_ON_ERROR)
                : $text;
        }, $method->getParameters());
        $return = $method->getReturnType();

        return $method->getName() . '(' . implode(', ', $parameters) . ')' . ($return === null ? '' : ': ' . $return);
    }
}
