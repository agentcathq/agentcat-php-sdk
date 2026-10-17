<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\McpSdk;

use AgentCat\McpSdk\Internal\Access\BuilderAccess;
use AgentCat\Tests\Contract\PrivateMemberManifest;
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
     * One row per accessor that touches an mcp/sdk member. The test reads the attributes itself, so their code runs
     * inside the test (coverage and mutation testing see it; a data provider runs before either starts).
     *
     * @return array<string, array{string}>
     */
    public static function mcpSdkAccessors(): array
    {
        $rows = [];
        foreach (PrivateMemberManifest::entries() as $entry) {
            if (str_starts_with($entry['member']->class, 'Mcp\\')) {
                $rows[$entry['accessor']] = [$entry['accessor']];
            }
        }

        return $rows;
    }

    #[DataProvider('mcpSdkAccessors')]
    public function testTheMembersStillHaveTheDeclaredShape(string $accessor): void
    {
        $members = array_column(array_filter(
            PrivateMemberManifest::entries(),
            static fn(array $entry): bool => $entry['accessor'] === $accessor,
        ), 'member');
        $this->assertNotSame([], $members);

        foreach ($members as $member) {
            $this->assertTrue(property_exists($member->class, $member->member), $member->class . '::$' . $member->member . ' is gone');

            $property = new \ReflectionProperty($member->class, $member->member);
            $this->assertSame($member->class, $property->getDeclaringClass()->getName());
            $this->assertSame($member->visibility === 'private', $property->isPrivate());
            $this->assertSame($member->visibility === 'protected', $property->isProtected());
            $this->assertSame($member->static, $property->isStatic());
            $this->assertSame($member->readonly, $property->isReadOnly());
            $this->assertSame($member->type, (string) $property->getType());
        }
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
        $this->assertNotSame([], array_filter($handlers, static fn(RequestHandlerInterface $handler): bool => $handler instanceof CallToolHandler));
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
        yield 'referenceHandler removed' => [self::remove('referenceHandler'), \Error::class, 'Mcp\Server\Builder::$referenceHandler'];
        yield 'container removed' => [self::remove('container'), \Error::class, 'Mcp\Server\Builder::$container'];
        yield 'logger removed' => [self::remove('logger'), \Error::class, 'Mcp\Server\Builder::$logger'];
    }

    /**
     * A future mcp/sdk can rename, remove or retype a member. probe() reads every member under StrictErrors, so each
     * kind of drift throws there instead of failing later on the request path.
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

    /** @return \Closure(Builder): void that unsets the member, as if a new mcp/sdk release had dropped it (RuntimeDriftTest uses it too) */
    public static function remove(string $member): \Closure
    {
        return static function (Builder $builder) use ($member): void {
            // @phpstan-ignore disallowed.method (simulates a removed member: PHP has no public way to unset a private property)
            $unset = \Closure::bind(static function (Builder $b) use ($member): void {
                unset($b->{$member}); // @phpstan-ignore property.dynamicName (the data provider names the member)
            }, null, Builder::class);
            $unset($builder);
        };
    }
}
