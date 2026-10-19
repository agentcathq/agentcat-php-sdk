<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract;

use AgentCat\Internal\Access\TouchesPrivateMember;
use AgentCat\Internal\Json\Json;
use AgentCat\LaravelMcp\Internal\Access\ServerAccess;
use AgentCat\McpSdk\Internal\Access\BuilderAccess;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ManifestTest extends TestCase
{
    public function testTheManifestListsExactlyTheMembersAgentCatDependsOn(): void
    {
        // A new private member or extended class must change this list, so a reviewer sees it.
        $this->assertSame(
            [
                'Laravel\Mcp\Server::$methods',
                'Laravel\Mcp\Server::$transport',
                'Laravel\Mcp\Server\Methods\CallTool::handle(Laravel\Mcp\Transport\JsonRpcRequest $request, Laravel\Mcp\Server\ServerContext $context): Generator|Laravel\Mcp\Transport\JsonRpcResponse',
                'Laravel\Mcp\Server\ToolInvoker::toErrorResponse(Throwable $e): Laravel\Mcp\Response',
                'Laravel\Mcp\Server\Transport\HttpTransport::$request',
                'Mcp\Schema\Result\CallToolResult::__construct(array $content, bool $isError = false, mixed $structuredContent = null, ?array $meta = null)',
                'Mcp\Schema\Result\ListToolsResult::__construct(array $tools, ?string $nextCursor = null)',
                'Mcp\Server::$protocol',
                'Mcp\Server\Builder::$container',
                'Mcp\Server\Builder::$logger',
                'Mcp\Server\Builder::$parts',
                'Mcp\Server\Builder::$referenceHandler',
                'Mcp\Server\Protocol::$requestHandlers',
                'symfony/mcp-bundle: builder method call addRequestHandlers(!tagged_iterator mcp.request_handler)',
                'symfony/mcp-bundle: builder without a configurator',
                'symfony/mcp-bundle: service mcp.server.<name>.builder tagged mcp.server.builder {server: <name>}',
            ],
            PrivateMemberManifest::members(),
        );
    }

    public function testEveryAccessorDeclaresWhatItTouches(): void
    {
        $classes = PrivateMemberManifest::accessorClasses();
        $this->assertContains(BuilderAccess::class, $classes);
        $this->assertContains(ServerAccess::class, $classes);

        foreach ($classes as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (!$method->isStatic() || $method->getName() === 'probe') {
                    continue;
                }
                $this->assertNotSame(
                    [],
                    $method->getAttributes(TouchesPrivateMember::class),
                    $class . '::' . $method->getName() . ' must declare the members it touches',
                );
            }
        }
    }

    #[Group('ac-01-90')]
    public function testNoMcpLibraryIsARuntimeRequirement(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/composer.json');
        $this->assertIsString($contents);
        $composer = Json::decode($contents);
        $this->assertInstanceOf(\stdClass::class, $composer);
        $this->assertInstanceOf(\stdClass::class, $composer->require);
        $this->assertInstanceOf(\stdClass::class, $composer->suggest);
        $this->assertInstanceOf(\stdClass::class, $composer->conflict);

        foreach (['mcp/sdk', 'laravel/mcp', 'symfony/mcp-bundle'] as $package) {
            $this->assertObjectNotHasProperty($package, $composer->require);
        }
        $this->assertObjectHasProperty('mcp/sdk', $composer->suggest);
        $this->assertSame('<0.8.1', $composer->conflict->{'mcp/sdk'});
        $this->assertObjectHasProperty('laravel/mcp', $composer->suggest);
        $this->assertSame('<1.0', $composer->conflict->{'laravel/mcp'});
        $this->assertObjectHasProperty('symfony/mcp-bundle', $composer->suggest);
    }
}
