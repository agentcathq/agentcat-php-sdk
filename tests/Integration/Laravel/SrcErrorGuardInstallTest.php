<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Laravel;

use AgentCat\Tests\Support\Laravel\BridgeTestCase;
use AgentCat\Tests\Support\Laravel\GuardPositionProvider;
use AgentCat\Tests\Support\SrcErrorGuard;

/**
 * Where `LaravelTestCase` puts the guard: in front of Laravel's error handler from before AgentCat's provider registers
 * until the test ends, once for each application.
 */
final class SrcErrorGuardInstallTest extends BridgeTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        GuardPositionProvider::$inFront = [];
        parent::setUp();
    }

    /** @return list<class-string> */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), GuardPositionProvider::class];
    }

    /** The providers register and boot behind the guard, after AgentCat's own. */
    public function testTheGuardIsInFrontWhileTheProvidersRegisterAndBoot(): void
    {
        $this->assertSame(['register' => true, 'boot' => true], GuardPositionProvider::$inFront);
    }

    public function testOneGuardIsInFrontOfTheApplicationsHandler(): void
    {
        $this->assertSame(1, SrcErrorGuard::installed());
        $this->assertTrue(SrcErrorGuard::inFront());
        $this->get('/agentcat-guard'); // a request through the HTTP kernel installs no handler of its own

        $this->assertSame(1, SrcErrorGuard::installed());
        $this->assertTrue(SrcErrorGuard::inFront());
    }

    /** A second application installs Laravel's handler again: a second guard goes in front of it, not two of one. */
    public function testARefreshedApplicationGetsAGuardOfItsOwn(): void
    {
        $this->refreshApplication();

        $this->assertSame(2, SrcErrorGuard::installed());
        $this->assertTrue(SrcErrorGuard::inFront());
    }
}
