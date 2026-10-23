<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\McpSdk;

use AgentCat\Internal\Engine\ThrowableStash;
use AgentCat\McpSdk\Internal\CapturingReferenceHandler;
use AgentCat\Tests\Support\Thrown;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;

final class CapturingReferenceHandlerTest extends TestCase
{
    public function testTheInnerHandlerAnswersUnchanged(): void
    {
        $handler = new CapturingReferenceHandler($this->inner(static fn(array $arguments): mixed => $arguments['n'] ?? null), new ThrowableStash());

        $this->assertSame(1, $handler->handle(self::tool(), ['n' => 1]));
        $this->assertSame(2, $handler->handle(self::tool(), ['n' => 2]));
    }

    public function testAToolExceptionIsStashedForItsRequestAndRethrown(): void
    {
        $error = new \RuntimeException('tool boom');
        $stash = new ThrowableStash();
        $handler = new CapturingReferenceHandler($this->inner(static function () use ($error): never {
            throw $error;
        }), $stash);
        $request = new \stdClass();

        $thrown = Thrown::by(\RuntimeException::class, static fn(): mixed => $handler->handle(self::tool(), ['_request' => $request]));

        $this->assertSame($error, $thrown);
        $this->assertSame($error, $stash->take($request));
    }

    public function testOnlyAToolExceptionWithARequestIsStashed(): void
    {
        $error = new \RuntimeException('prompt boom');
        $stash = new ThrowableStash();
        $handler = new CapturingReferenceHandler($this->inner(static function () use ($error): never {
            throw $error;
        }), $stash);
        $request = new \stdClass();

        $prompt = Thrown::by(\RuntimeException::class, static fn(): mixed => $handler->handle(new ElementReference(static fn(): null => null), ['_request' => $request]));
        $noRequest = Thrown::by(\RuntimeException::class, static fn(): mixed => $handler->handle(self::tool(), ['_request' => 'not an object']));

        $this->assertSame([$error, $error], [$prompt, $noRequest]);
        $this->assertNull($stash->take($request));
    }

    private static function tool(): ToolReference
    {
        return new ToolReference(new Tool(name: 't', title: null, inputSchema: ['type' => 'object', 'properties' => [], 'required' => []], description: null, annotations: null), static fn(): null => null);
    }

    /** @param \Closure(array<string, mixed>): mixed $answer */
    private function inner(\Closure $answer): ReferenceHandlerInterface
    {
        return new readonly class ($answer) implements ReferenceHandlerInterface {
            /** @param \Closure(array<string, mixed>): mixed $answer */
            public function __construct(private \Closure $answer) {}

            #[\Override]
            public function handle(ElementReference $reference, array $arguments): mixed
            {
                return ($this->answer)($arguments);
            }
        };
    }
}
