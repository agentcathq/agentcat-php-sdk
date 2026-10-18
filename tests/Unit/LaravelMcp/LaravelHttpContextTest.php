<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\LaravelMcp;

use AgentCat\LaravelMcp\Internal\LaravelHttpContext;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('ac-02-43')]
final class LaravelHttpContextTest extends TestCase
{
    public function testTheMethodTheFullUrlAndTheHeadersComeFromTheRequest(): void
    {
        $request = Request::create('https://api.test/mcp/weather?unit=c', 'POST', server: ['HTTP_X_AGENTCAT_HEALTH_RUN' => 'S', 'HTTP_AUTHORIZATION' => 'Bearer t']);

        $context = LaravelHttpContext::of($request);

        $this->assertSame('POST', $context->method);
        $this->assertSame('https://api.test/mcp/weather?unit=c', $context->url);
        $this->assertSame('S', $context->headers['x-agentcat-health-run'] ?? null);
        $this->assertSame('Bearer t', $context->headers['authorization'] ?? null);
    }

    public function testRepeatedValuesAreJoinedAndNamesAreLowerCase(): void
    {
        $request = Request::create('/mcp', 'GET');
        $request->headers->replace([]);
        $request->headers->set('X-Multi', ['a', 'b']);
        $request->headers->set('X-Empty', null);

        $this->assertSame(['x-multi' => 'a, b', 'x-empty' => ''], LaravelHttpContext::of($request)->headers);
    }
}
