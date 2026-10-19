<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Transport;

use AgentCat\Internal\Support\BridgeConfig;
use AgentCat\Internal\Transport\ApiBaseUrl;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ApiBaseUrlTest extends TestCase
{
    private const array NAMES = ['AGENTCAT_API_URL', 'MCPCAT_API_URL'];

    /** @var array<string, array{getenv: string|false, env: mixed, server: mixed}> */
    private array $saved = [];

    #[\Override]
    protected function setUp(): void
    {
        foreach (self::NAMES as $name) {
            $this->saved[$name] = ['getenv' => getenv($name), 'env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null];
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        BridgeConfig::reset();
        foreach ($this->saved as $name => $saved) {
            putenv($saved['getenv'] === false ? $name : $name . '=' . $saved['getenv']);
            unset($_ENV[$name], $_SERVER[$name]);
            if ($saved['env'] !== null) {
                $_ENV[$name] = $saved['env'];
            }
            if ($saved['server'] !== null) {
                $_SERVER[$name] = $saved['server'];
            }
        }
    }

    #[Group('ac-01-58')]
    #[Group('ac-07-1')]
    public function testDefaultsToTheAgentCatApi(): void
    {
        $this->assertSame('https://api.agentcat.com', ApiBaseUrl::resolve());
        $this->assertSame('https://api.agentcat.com/v1/public/events/', ApiBaseUrl::endpoint(ApiBaseUrl::resolve()));
    }

    #[Group('ac-01-55')]
    #[Group('ac-07-3')]
    public function testOptionBeatsBothEnvironmentVariables(): void
    {
        putenv('AGENTCAT_API_URL=https://a.test');
        putenv('MCPCAT_API_URL=https://b.test');

        $this->assertSame('https://opt.test', ApiBaseUrl::resolve('https://opt.test'));
    }

    #[Group('ac-01-56')]
    #[Group('ac-07-3')]
    public function testAgentCatVariableBeatsMcpCatVariable(): void
    {
        putenv('AGENTCAT_API_URL=https://a.test');
        putenv('MCPCAT_API_URL=https://b.test');

        $this->assertSame('https://a.test', ApiBaseUrl::resolve());
    }

    /** A bridge's configuration (a cached Laravel configuration) comes after the option, before both variables. */
    public function testAConfiguredUrlComesAfterTheOptionAndBeforeTheVariables(): void
    {
        putenv('AGENTCAT_API_URL=https://a.test');
        putenv('MCPCAT_API_URL=https://b.test');
        BridgeConfig::configure(['AGENTCAT_API_URL' => 'https://configured.test/'], false);

        $this->assertSame('https://configured.test', ApiBaseUrl::resolve());
        $this->assertSame('https://opt.test', ApiBaseUrl::resolve('https://opt.test'));
    }

    #[Group('ac-01-57')]
    #[Group('ac-07-3')]
    public function testMcpCatVariableAlone(): void
    {
        $_SERVER['MCPCAT_API_URL'] = 'https://b.test';

        $this->assertSame('https://b.test', ApiBaseUrl::resolve());
    }

    #[Group('ac-01-59')]
    public function testEmptyOptionAndEmptyVariableAreSkipped(): void
    {
        putenv('AGENTCAT_API_URL=');
        putenv('MCPCAT_API_URL=https://b.test');

        $this->assertSame('https://b.test', ApiBaseUrl::resolve(''));
    }

    #[Group('ac-01-59')]
    public function testEverythingEmptyFallsBackToTheDefault(): void
    {
        putenv('AGENTCAT_API_URL=');
        putenv('MCPCAT_API_URL=');

        $this->assertSame('https://api.agentcat.com', ApiBaseUrl::resolve(''));
    }

    #[Group('ac-07-2')]
    public function testTrailingSlashesAreTrimmed(): void
    {
        $this->assertSame('https://ingest.example.test', ApiBaseUrl::resolve('https://ingest.example.test/'));
        $this->assertSame('https://x.test', ApiBaseUrl::resolve('https://x.test///'));
        putenv('AGENTCAT_API_URL=https://a.test/');
        $this->assertSame('https://a.test', ApiBaseUrl::resolve());
    }

    #[Group('ac-07-2')]
    public function testEndpointHasNoDoubleSlash(): void
    {
        $this->assertSame(
            'https://ingest.example.test/v1/public/events/',
            ApiBaseUrl::endpoint('https://ingest.example.test/'),
        );
        $this->assertSame(
            'https://ingest.example.test/v1/public/events/',
            ApiBaseUrl::endpoint(ApiBaseUrl::resolve('https://ingest.example.test/')),
        );
        $this->assertSame('http://127.0.0.1:8080/c/abc/v1/public/events/', ApiBaseUrl::endpoint('http://127.0.0.1:8080/c/abc'));
    }
}
