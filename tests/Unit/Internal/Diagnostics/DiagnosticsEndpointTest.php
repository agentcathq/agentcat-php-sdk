<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Diagnostics;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Diagnostics\DiagnosticsEndpoint;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\Environment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class DiagnosticsEndpointTest extends TestCase
{
    private Environment $environment;

    #[\Override]
    protected function setUp(): void
    {
        $this->environment = Environment::clear('AGENTCAT_DIAGNOSTICS_ENDPOINT', 'AGENTCAT_DIAGNOSTICS_TOKEN', 'DIAGNOSTICS_ENDPOINT', 'DIAGNOSTICS_TOKEN');
        putenv('AGENTCAT_DIAGNOSTICS_ENDPOINT=' . MockCollector::closedPortUrl()); // fail closed (ruling P8)
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->environment->restore();
    }

    #[Group('ac-06-54')]
    public function testWithoutVariablesTheDefaultCollectorAndTokenAreUsed(): void
    {
        putenv('AGENTCAT_DIAGNOSTICS_ENDPOINT'); // this test posts nothing

        $this->assertSame('https://otel.agentcat.com/v1/logs', DiagnosticsEndpoint::url());
        $this->assertSame('dgk_sdk_diag_3f9a2c7e1b8d4065af2e9c1d7b6a4f80', DiagnosticsEndpoint::token());
    }

    /** @return iterable<string, array{string, string}> */
    public static function bases(): iterable
    {
        yield 'trailing slash' => ['https://c.example/', 'https://c.example/v1/logs'];
        yield 'no path' => ['https://c.example', 'https://c.example/v1/logs'];
        yield 'with a path' => ['https://c.example/base', 'https://c.example/base/v1/logs'];
        yield 'full URL' => ['https://c.example/v1/logs', 'https://c.example/v1/logs'];
        yield 'full URL with slashes' => ['https://c.example/v1/logs//', 'https://c.example/v1/logs'];
    }

    #[DataProvider('bases')]
    #[Group('ac-06-55')]
    public function testTheVariableSetsTheCollector(string $base, string $expected): void
    {
        putenv('AGENTCAT_DIAGNOSTICS_ENDPOINT=' . $base);

        $this->assertSame($expected, DiagnosticsEndpoint::url());
    }

    public function testTheVariableIsReadFromTheServerArrayToo(): void
    {
        putenv('AGENTCAT_DIAGNOSTICS_ENDPOINT');
        $_SERVER['AGENTCAT_DIAGNOSTICS_ENDPOINT'] = 'https://fpm.example';
        $_SERVER['AGENTCAT_DIAGNOSTICS_TOKEN'] = 'fpm';

        $this->assertSame('https://fpm.example/v1/logs', DiagnosticsEndpoint::url());
        $this->assertSame('fpm', DiagnosticsEndpoint::token());
    }

    /** A10 (D50): the unprefixed names belong to the application; AgentCat never reads them. */
    #[Group('ac-06-55')]
    public function testTheUnprefixedVariablesOfTheApplicationAreIgnored(): void
    {
        putenv('AGENTCAT_DIAGNOSTICS_ENDPOINT');
        putenv('DIAGNOSTICS_ENDPOINT=https://diagnostics.app.example');
        putenv('DIAGNOSTICS_TOKEN=the-applications-own-secret');
        $_SERVER['DIAGNOSTICS_ENDPOINT'] = $_ENV['DIAGNOSTICS_ENDPOINT'] = 'https://diagnostics.app.example';
        $_SERVER['DIAGNOSTICS_TOKEN'] = $_ENV['DIAGNOSTICS_TOKEN'] = 'the-applications-own-secret';

        $this->assertSame('https://otel.agentcat.com/v1/logs', DiagnosticsEndpoint::url());
        $this->assertSame(Constants::DEFAULT_DIAGNOSTICS_TOKEN, DiagnosticsEndpoint::token());
    }

    #[Group('ac-06-55')]
    public function testTheTokenVariableReplacesTheDefaultUnlessItIsEmpty(): void
    {
        putenv('AGENTCAT_DIAGNOSTICS_TOKEN=t');
        $this->assertSame('t', DiagnosticsEndpoint::token());

        putenv('AGENTCAT_DIAGNOSTICS_TOKEN=');
        $this->assertSame(Constants::DEFAULT_DIAGNOSTICS_TOKEN, DiagnosticsEndpoint::token());
    }
}
