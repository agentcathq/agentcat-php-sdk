<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\LaravelMcp;

use AgentCat\LaravelMcp\Internal\Access\HttpTransportAccess;
use AgentCat\LaravelMcp\Internal\Access\ServerAccess;
use AgentCat\Tests\Contract\MemberShape;
use AgentCat\Tests\Support\LaravelMcp\EmptyServer;
use AgentCat\Tests\Support\LaravelMcp\LaravelTestCase;
use AgentCat\Tests\Support\LaravelMcp\LineTransport;
use AgentCat\Tests\Support\LaravelMcp\RecordingMethod;
use AgentCat\Tests\Support\Members;
use AgentCat\Tests\Support\Thrown;
use Illuminate\Http\Request;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Methods\ListTools;
use Laravel\Mcp\Server\Transport\HttpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** The protected members of laravel/mcp that AgentCat reads (D21), checked against the installed laravel/mcp. */
#[Group('contract')]
final class ServerMembersTest extends LaravelTestCase
{
    /**
     * One row per accessor that touches a laravel/mcp member.
     *
     * @return array<string, array{string}>
     */
    public static function members(): array
    {
        return MemberShape::accessorsIn('Laravel\\');
    }

    #[DataProvider('members')]
    public function testEachMemberStillHasTheShapeAgentCatReads(string $accessor): void
    {
        MemberShape::assertDeclared($accessor);
    }

    public function testTheAccessorsReturnTheLiveValues(): void
    {
        $transport = new HttpTransport(Request::create('/mcp', 'POST'));
        $server = $this->app?->make(EmptyServer::class, ['transport' => $transport]);
        $this->assertInstanceOf(EmptyServer::class, $server);

        $this->assertSame(ListTools::class, ServerAccess::methods($server)['tools/list'] ?? null);
        $this->assertSame($transport, ServerAccess::transport($server));
        $this->assertSame('/mcp', HttpTransportAccess::request($transport)->getPathInfo());
        ServerAccess::probe($server);
    }

    public function testTheMethodMapIsReadAfterEachChange(): void
    {
        $server = new EmptyServer(new LineTransport());
        $server->addMethod('tools/list', RecordingMethod::class);

        $this->assertSame(RecordingMethod::class, ServerAccess::methods($server)['tools/list'] ?? null);
    }

    /** @return iterable<string, array{\Closure(object): void, string}> */
    public static function drift(): iterable
    {
        yield 'methods uninitialized' => [Members::uninitialize(Server::class, 'methods'), 'Laravel\Mcp\Server::$methods must not be accessed before initialization'];
        yield 'transport uninitialized' => [Members::uninitialize(Server::class, 'transport'), 'Laravel\Mcp\Server::$transport must not be accessed before initialization'];
    }

    /**
     * probe() reads every member, so a member that cannot be read throws there instead of failing later on the request
     * path. PHP cannot remove a declared property: the cases leave a member uninitialized.
     *
     * @param \Closure(object): void $drift
     */
    #[DataProvider('drift')]
    public function testProbeThrowsWhenAMemberDrifts(\Closure $drift, string $message): void
    {
        $server = new EmptyServer(new LineTransport());
        $drift($server);

        $error = Thrown::by(\Error::class, static fn() => ServerAccess::probe($server));

        $this->assertStringContainsString($message, $error->getMessage());
    }

    public function testAnUnreadableRequestThrows(): void
    {
        $transport = new HttpTransport(Request::create('/mcp', 'POST'));
        Members::uninitialize(HttpTransport::class, 'request')($transport);

        $error = Thrown::by(\Error::class, static fn(): Request => HttpTransportAccess::request($transport));

        $this->assertStringContainsString('Laravel\Mcp\Server\Transport\HttpTransport::$request must not be accessed before initialization', $error->getMessage());
    }
}
