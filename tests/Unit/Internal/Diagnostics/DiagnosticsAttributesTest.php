<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Diagnostics;

use AgentCat\Internal\Diagnostics\DiagnosticsAttributes;
use AgentCat\Internal\SdkVersion;
use AgentCat\Tests\Support\Environment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** D48: PHP values in the Node vocabulary. */
final class DiagnosticsAttributesTest extends TestCase
{
    private Environment $environment;

    #[\Override]
    protected function setUp(): void
    {
        $this->environment = Environment::clear('APP_ENV');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->environment->restore();
    }

    #[Group('ac-06-56')]
    public function testTheResourceOfAProjectNamesTheProjectSdkRuntimeAndHost(): void
    {
        $mcp = SdkVersion::installed('mcp/sdk');
        $this->assertNotNull($mcp, 'mcp/sdk is a dev dependency');

        $this->assertSame([
            'agentcat.project_id' => 'proj_x',
            'agentcat.sdk.language' => 'php',
            'agentcat.sdk.version' => SdkVersion::get(),
            'agentcat.mcp_sdk.version' => $mcp,
            'process.runtime.name' => 'php',
            'process.runtime.version' => PHP_VERSION,
            'process.pid' => (string) getmypid(),
            'os.type' => DiagnosticsAttributes::osType(PHP_OS_FAMILY),
            'os.version' => php_uname('r'),
            'host.arch' => DiagnosticsAttributes::arch(php_uname('m')),
        ], $this->pairs(DiagnosticsAttributes::resource('proj_x')));
    }

    public function testEveryResourceValueIsANonEmptyString(): void
    {
        foreach (DiagnosticsAttributes::resource('proj_x') as $attribute) {
            $this->assertNotSame('', $attribute['value']['stringValue'], $attribute['key']);
        }
    }

    public function testAppEnvBecomesTheDeploymentEnvironmentAtTheEnd(): void
    {
        putenv('APP_ENV=staging');

        $pairs = $this->pairs(DiagnosticsAttributes::resource('proj_x'));

        $this->assertSame('deployment.environment', array_key_last($pairs));
        $this->assertSame('staging', $pairs['deployment.environment'] ?? null);
    }

    #[Group('ac-06-56')]
    public function testWithoutAProjectTheInstallIdComesFirst(): void
    {
        $pairs = $this->pairs(DiagnosticsAttributes::resource(''));

        $this->assertSame('agentcat.install_id', array_key_first($pairs));
        $this->assertArrayNotHasKey('agentcat.project_id', $pairs);
        $id = $pairs['agentcat.install_id'] ?? '';
        $this->assertSame(DiagnosticsAttributes::installId((string) gethostname(), 'file://' . dirname(__DIR__, 4)), $id);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $id);
    }

    public function testEveryRecordCarriesTheVersions(): void
    {
        $this->assertSame([
            'agentcat.sdk.version' => SdkVersion::get(),
            'process.runtime.version' => PHP_VERSION,
            'agentcat.mcp_sdk.version' => (string) SdkVersion::installed('mcp/sdk'),
        ], $this->pairs(DiagnosticsAttributes::record()));
    }

    public function testTheInstallIdHashesTheHostAndThePath(): void
    {
        $this->assertSame(substr(hash('sha256', 'host|file:///app'), 0, 16), DiagnosticsAttributes::installId('host', 'file:///app'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function families(): iterable
    {
        yield 'Linux' => ['Linux', 'linux'];
        yield 'Darwin' => ['Darwin', 'darwin'];
        yield 'Windows' => ['Windows', 'win32'];
        yield 'BSD' => ['BSD', 'bsd'];
        yield 'Solaris' => ['Solaris', 'solaris'];
        yield 'Unknown' => ['Unknown', 'unknown'];
    }

    /** Ruling P12: only linux, darwin and win32 are mapped. */
    #[DataProvider('families')]
    public function testTheOsTypeUsesTheNodeNamesForTheThreeMainSystems(string $family, string $expected): void
    {
        $this->assertSame($expected, DiagnosticsAttributes::osType($family));
    }

    /** @return iterable<string, array{string, string}> */
    public static function machines(): iterable
    {
        yield 'x86_64' => ['x86_64', 'x64'];
        yield 'amd64' => ['amd64', 'x64'];
        yield 'AMD64 (Windows)' => ['AMD64', 'x64'];
        yield 'aarch64' => ['aarch64', 'arm64'];
        yield 'arm64' => ['arm64', 'arm64'];
        yield 'ARM64 (Windows)' => ['ARM64', 'arm64'];
        yield 'i686' => ['i686', 'i686'];
        yield 'riscv64' => ['riscv64', 'riscv64'];
        yield 'mixed case kept' => ['Power Macintosh', 'Power Macintosh'];
    }

    /** Ruling P12: only x64 and arm64 are mapped; any other machine name is sent as is. */
    #[DataProvider('machines')]
    public function testTheArchitectureUsesTheNodeNamesForX64AndArm64(string $machine, string $expected): void
    {
        $this->assertSame($expected, DiagnosticsAttributes::arch($machine));
    }

    /**
     * @param list<array{key: string, value: array{stringValue: string}}> $attributes
     *
     * @return array<string, string>
     */
    private function pairs(array $attributes): array
    {
        $pairs = [];
        foreach ($attributes as $attribute) {
            $this->assertArrayNotHasKey($attribute['key'], $pairs, 'each key once');
            $pairs[$attribute['key']] = $attribute['value']['stringValue'];
        }

        return $pairs;
    }
}
