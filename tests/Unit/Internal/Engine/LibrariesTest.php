<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Engine;

use AgentCat\Internal\Engine\Libraries;
use AgentCat\LaravelMcp\LaravelMcpAdapter;
use AgentCat\LaravelMcp\LaravelMcpLibrary;
use AgentCat\McpSdk\McpSdkAdapter;
use AgentCat\McpSdk\McpSdkLibrary;
use Mcp\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class LibrariesTest extends TestCase
{
    public function testTheMcpSdkLibraryIsAskedFirstAndTheLaravelOneSecond(): void
    {
        $libraries = Libraries::all();

        $this->assertCount(2, $libraries);
        $this->assertInstanceOf(McpSdkLibrary::class, $libraries[0]);
        $this->assertInstanceOf(LaravelMcpLibrary::class, $libraries[1]);
    }

    public function testABuilderGoesToTheMcpSdkLibrary(): void
    {
        $builder = Server::builder();

        $this->assertInstanceOf(McpSdkLibrary::class, Libraries::for($builder));
        $this->assertSame('mcp-sdk/builder', Libraries::for($builder)->sdk($builder));
    }

    #[Group('ac-02-2')]
    public function testAnythingElseHasNoLibraryAndKeepsItsSignals(): void
    {
        $built = Server::builder();
        $built->build();

        $this->assertNull(Libraries::for(new \stdClass()));
        $this->assertSame('', Libraries::fingerprint(new \stdClass()));
        $this->assertSame('mcpSdkServer', Libraries::fingerprint(Server::builder()->build()));
        $this->assertSame('mcpSdkBuilder,built', Libraries::fingerprint($built));
        $this->assertNull(Libraries::trackedServer(new \stdClass()));
        $this->assertNull(Libraries::trackerOf(new \stdClass()));
        $this->assertNull(Libraries::trackerOf(Server::builder()->build()), 'a Server whose Builder is not tracked');
    }

    public function testTheSupportMatrixHasOneSentencePerLibrary(): void
    {
        $this->assertSame(McpSdkAdapter::SUPPORT_MATRIX . ' ' . LaravelMcpAdapter::SUPPORT_MATRIX, Libraries::supportMatrix());
        $this->assertSame(
            'AgentCat supports mcp/sdk 0.8.1+ (pass the Mcp\Server\Builder to AgentCat::track() before build()). AgentCat supports laravel/mcp 1.0+ (the Laravel service provider tracks every server).',
            Libraries::supportMatrix(),
        );
    }
}
