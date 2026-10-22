<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit;

use AgentCat\ToolCall;
use PHPUnit\Framework\TestCase;

final class ToolCallTest extends TestCase
{
    public function testDefaults(): void
    {
        $call = new ToolCall('echo', ['a' => 1]);

        $this->assertSame('echo', $call->toolName);
        $this->assertSame(['a' => 1], $call->arguments);
        $this->assertSame([], $call->meta);
        $this->assertSame([], $call->headers);
        $this->assertNull($call->clientName);
        $this->assertNull($call->clientVersion);
        $this->assertNull($call->protocolVersion);
        $this->assertNull($call->transportSessionId);
        $this->assertNull($call->request);
        $this->assertNull($call->context);
    }

    public function testEveryFieldKeepsItsValue(): void
    {
        $request = new \stdClass();
        $context = new \ArrayObject();

        $call = new ToolCall(
            toolName: 'echo',
            arguments: ['x' => 'y'],
            meta: ['m' => 1],
            headers: ['user-agent' => 'curl'],
            clientName: 'cli',
            clientVersion: '1.2',
            protocolVersion: '2025-06-18',
            transportSessionId: 'sess',
            request: $request,
            context: $context,
        );

        $this->assertSame(['m' => 1], $call->meta);
        $this->assertSame(['user-agent' => 'curl'], $call->headers);
        $this->assertSame('cli', $call->clientName);
        $this->assertSame('1.2', $call->clientVersion);
        $this->assertSame('2025-06-18', $call->protocolVersion);
        $this->assertSame('sess', $call->transportSessionId);
        $this->assertSame($request, $call->request);
        $this->assertSame($context, $call->context);
    }

    public function testClassIsReadonly(): void
    {
        $this->assertTrue((new \ReflectionClass(ToolCall::class))->isReadOnly());
    }
}
