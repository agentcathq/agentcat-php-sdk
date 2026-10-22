<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\McpSdk;

use AgentCat\McpSdk\Internal\Access\BuilderAccess;
use AgentCat\Tests\Contract\MemberShape;
use AgentCat\Tests\Support\McpSdk\BuilderMembers;
use AgentCat\Tests\Support\Thrown;
use Mcp\Capability\Registry\Container;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Configuration;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[Group('contract')]
final class BuilderMembersTest extends TestCase
{
    /**
     * One row per accessor that touches an mcp/sdk member.
     *
     * @return array<string, array{string}>
     */
    public static function mcpSdkAccessors(): array
    {
        return MemberShape::accessorsIn('Mcp\\');
    }

    #[DataProvider('mcpSdkAccessors')]
    public function testTheMembersStillHaveTheDeclaredShape(string $accessor): void
    {
        MemberShape::assertDeclared($accessor);
    }

    public function testBuiltPartsHoldWhatAgentCatReads(): void
    {
        $builder = Server::builder()->setServerInfo('contract', '1.2.3');
        $builder->build();

        $parts = (new \ReflectionProperty(Builder::class, 'parts'))->getValue($builder);
        $this->assertIsArray($parts);
        $this->assertArrayHasKey('requestHandlers', $parts);
        $this->assertIsList($parts['requestHandlers']);
        $this->assertContainsOnlyInstancesOf(RequestHandlerInterface::class, $parts['requestHandlers']);
        $this->assertArrayHasKey('configuration', $parts);
        $this->assertInstanceOf(Configuration::class, $parts['configuration']);
        $this->assertSame('contract', $parts['configuration']->serverInfo->name);
        $this->assertSame('1.2.3', $parts['configuration']->serverInfo->version);
    }

    public function testAccessorsReturnTheLiveValues(): void
    {
        $builder = Server::builder();
        $this->assertNull(BuilderAccess::requestHandlers($builder));
        $this->assertNull(BuilderAccess::serverInfo($builder));
        $this->assertFalse(BuilderAccess::isBuilt($builder));
        $this->assertNull(BuilderAccess::referenceHandler($builder));
        $this->assertNull(BuilderAccess::container($builder));
        $this->assertNull(BuilderAccess::logger($builder));

        $logger = new NullLogger();
        $container = new Container();
        $referenceHandler = new ReferenceHandler($container);
        $builder->setServerInfo('contract', '1.2.3')->setLogger($logger)->setContainer($container)->setReferenceHandler($referenceHandler);
        $this->assertSame($logger, BuilderAccess::logger($builder));
        $this->assertSame($container, BuilderAccess::container($builder));
        $this->assertSame($referenceHandler, BuilderAccess::referenceHandler($builder));
        $this->assertFalse(BuilderAccess::isBuilt($builder));

        $builder->build();
        $this->assertTrue(BuilderAccess::isBuilt($builder));
        $handlers = BuilderAccess::requestHandlers($builder);
        $this->assertNotNull($handlers);
        $this->assertNotSame([], array_filter($handlers, static fn(mixed $handler): bool => $handler instanceof CallToolHandler));
        $this->assertSame('contract', BuilderAccess::serverInfo($builder)?->name);
        $this->assertSame('1.2.3', BuilderAccess::serverInfo($builder)?->version);
    }

    public function testProbePassesOnTheInstalledVersion(): void
    {
        $fresh = Server::builder();
        BuilderAccess::probe($fresh);
        $this->assertFalse(BuilderAccess::isBuilt($fresh), 'probe() must not assemble the parts');

        $built = Server::builder()->setServerInfo('contract', '1.2.3');
        $built->build();
        BuilderAccess::probe($built);
        $this->assertTrue(BuilderAccess::isBuilt($built));
    }

    /** @return iterable<string, array{\Closure(Builder): void, class-string<\Throwable>, string}> */
    public static function driftProvider(): iterable
    {
        $parts = static fn(mixed $value): \Closure => static function (Builder $builder) use ($value): void {
            (new \ReflectionProperty(Builder::class, 'parts'))->setValue($builder, $value);
        };

        yield 'parts lost requestHandlers' => [$parts([]), \ErrorException::class, 'Undefined array key "requestHandlers"'];
        yield 'requestHandlers retyped' => [$parts(['requestHandlers' => 'handlers']), \TypeError::class, 'must be of type ?array, string returned'];
        yield 'parts lost configuration' => [$parts(['requestHandlers' => []]), \ErrorException::class, 'Undefined array key "configuration"'];
        yield 'referenceHandler uninitialized' => [BuilderMembers::uninitialize('referenceHandler'), \Error::class, 'Mcp\Server\Builder::$referenceHandler'];
        yield 'container uninitialized' => [BuilderMembers::uninitialize('container'), \Error::class, 'Mcp\Server\Builder::$container'];
        yield 'logger uninitialized' => [BuilderMembers::uninitialize('logger'), \Error::class, 'Mcp\Server\Builder::$logger'];
    }

    /**
     * probe() reads every member under StrictErrors, so a member that cannot be read, or a `$parts` entry that is gone
     * or holds another type than the accessor returns, throws there instead of failing later on the request path. PHP
     * cannot remove a declared property: the cases change what `$parts` holds, or leave a member uninitialized.
     *
     * @param \Closure(Builder): void $drift
     * @param class-string<\Throwable> $class
     */
    #[DataProvider('driftProvider')]
    public function testProbeThrowsWhenAMemberDrifts(\Closure $drift, string $class, string $message): void
    {
        $builder = Server::builder();
        $drift($builder);

        $error = Thrown::by($class, static fn() => BuilderAccess::probe($builder));
        $this->assertSame($error::class, $class);
        $this->assertStringContainsString($message, $error->getMessage());
    }
}
