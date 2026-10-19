<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\Symfony;

use AgentCat\Tests\Contract\PrivateMemberManifest;
use AgentCat\Tests\Support\Symfony\DefinitionsProbe;
use AgentCat\Tests\Support\Symfony\SymfonyTestCase;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use Mcp\Server;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;

/**
 * AgentCat's bundle finds each symfony/mcp-bundle server by these service definitions, read at compile time (Plan E2
 * header conflict 3): the builder's service ID, its tag and `server` attribute, the `addRequestHandlers` method call
 * with the tagged request handlers, and no configurator of the bundle's own. A bundle release that changes one of them
 * breaks the tracking of every bundle server.
 */
#[Group('contract')]
final class BundleServicesTest extends SymfonyTestCase
{
    #[MaximumDuration(1000)] // compiles a container: 0.1 to 0.5 s measured on PHP 8.3 and 8.5
    public function testEachServerBuilderIsTaggedAndAddsTheTaggedHandlersInAMethodCall(): void
    {
        $probe = new DefinitionsProbe();
        $this->kernel(withAgentCat: false, probe: $probe);

        $builder = $probe->builders['conformance'] ?? null;
        $this->assertIsArray($builder, 'no service tagged mcp.server.builder with server: conformance');
        $this->assertSame('mcp.server.conformance.builder', $builder['id']);
        $this->assertEquals([Server::class, 'builder'], $builder['factory']);
        $this->assertNull($builder['configurator']);
        $calls = array_values(array_filter($builder['calls'], static fn(array $call): bool => $call[0] === 'addRequestHandlers'));
        $this->assertCount(1, $calls);
        $argument = $calls[0][1][0] ?? null;
        $this->assertInstanceOf(TaggedIteratorArgument::class, $argument);
        $this->assertSame('mcp.request_handler', $argument->getTag());
    }

    #[MaximumDuration(1000)]
    public function testTheServerIsBuiltFromItsBuilder(): void
    {
        $kernel = $this->kernel(withAgentCat: false);

        $this->assertInstanceOf(Server::class, $kernel->getContainer()->get('mcp.server.conformance'));
    }

    public function testTheManifestNamesTheBundleInternals(): void
    {
        $bundle = array_values(array_filter(PrivateMemberManifest::members(), static fn(string $member): bool => str_starts_with($member, 'symfony/mcp-bundle: ')));

        $this->assertSame([
            'symfony/mcp-bundle: builder method call addRequestHandlers(!tagged_iterator mcp.request_handler)',
            'symfony/mcp-bundle: builder without a configurator',
            'symfony/mcp-bundle: service mcp.server.<name>.builder tagged mcp.server.builder {server: <name>}',
        ], $bundle);
    }
}
