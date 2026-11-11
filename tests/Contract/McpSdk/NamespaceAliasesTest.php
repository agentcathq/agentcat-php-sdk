<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\McpSdk;

use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Capability\RegistryInterface;
use Mcp\Server;
use PHPUnit\Framework\TestCase;

final class NamespaceAliasesTest extends TestCase
{
    public function testTheBuilderReturnsAServerRecognizedByTheAdapter(): void
    {
        $server = Server::builder()->setServerInfo('namespace-test', '1.0')->build();

        $this->assertInstanceOf(Server::class, $server);
        $this->assertSame(class_exists('Mcp\\Server\\Server') ? 'Mcp\\Server\\Server' : Server::class, $server::class);
    }

    public function testTheRenamedClassesAndInterfacesKeepTheirIdentity(): void
    {
        foreach ([
            SchemaValidator::class => 'Mcp\\Server\\Capability\\Discovery\\SchemaValidator',
            RegistryInterface::class => 'Mcp\\Server\\Capability\\RegistryInterface',
        ] as $legacy => $renamed) {
            $expected = class_exists($renamed) || interface_exists($renamed) ? $renamed : $legacy;
            $this->assertSame($expected, (new \ReflectionClass($legacy))->getName());
        }
    }
}
