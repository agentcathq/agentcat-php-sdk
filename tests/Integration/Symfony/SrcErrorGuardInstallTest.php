<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Symfony;

use AgentCat\Tests\Support\SrcErrorGuard;
use AgentCat\Tests\Support\Symfony\SymfonyTestCase;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Where `SymfonyTestCase` puts the guard: in front from before the first kernel boots until the test ends, once. */
final class SrcErrorGuardInstallTest extends SymfonyTestCase
{
    #[MaximumDuration(1000)]
    public function testOneGuardIsInFrontForEveryKernelOfATest(): void
    {
        $this->http($this->kernel(agentcat: $this->on('proj_ggggggggggggggggggggggggggg')))->listTools();
        $this->kernel(agentcat: $this->on('proj_ggggggggggggggggggggggggggg'));

        $this->assertSame(1, SrcErrorGuard::installed());
        $this->assertTrue(SrcErrorGuard::inFront());
    }

    /** The bundles build the container behind the guard (AgentCat's compiler pass runs then). */
    #[MaximumDuration(1000)]
    public function testTheGuardIsInFrontWhileTheContainerBuilds(): void
    {
        $probe = new class implements CompilerPassInterface {
            public ?bool $inFront = null;

            #[\Override]
            public function process(ContainerBuilder $container): void
            {
                $this->inFront = SrcErrorGuard::inFront();
            }
        };

        $this->kernel(agentcat: $this->on('proj_ggggggggggggggggggggggggggg'), probe: $probe);

        $this->assertTrue($probe->inFront);
    }
}
