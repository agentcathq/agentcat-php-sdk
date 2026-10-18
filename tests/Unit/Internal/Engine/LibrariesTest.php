<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Engine;

use AgentCat\Internal\Engine\Libraries;
use AgentCat\McpSdk\McpSdkLibrary;
use Mcp\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class LibrariesTest extends TestCase
{
    public function testTheMcpSdkLibraryIsAskedFirst(): void
    {
        $this->assertInstanceOf(McpSdkLibrary::class, Libraries::all()[0] ?? null);
    }

    public function testABuilderGoesToTheMcpSdkLibrary(): void
    {
        $this->assertInstanceOf(McpSdkLibrary::class, Libraries::for(Server::builder()));
        $this->assertSame('mcp-sdk/builder', Libraries::for(Server::builder())->sdk());
    }

    #[Group('ac-02-2')]
    public function testAnythingElseHasNoLibraryAndKeepsItsSignals(): void
    {
        $this->assertNull(Libraries::for(new \stdClass()));
        $this->assertSame('', Libraries::fingerprint(new \stdClass()));
        $this->assertSame('mcpSdkServer', Libraries::fingerprint(Server::builder()->build()));
        $this->assertNull(Libraries::trackedServer(new \stdClass()));
    }

    public function testTheSupportMatrixHasOneSentencePerLibrary(): void
    {
        $this->assertSame(
            'AgentCat supports mcp/sdk 0.8.1+ (pass the Mcp\Server\Builder to AgentCat::track() before build()).',
            Libraries::supportMatrix(),
        );
    }
}
