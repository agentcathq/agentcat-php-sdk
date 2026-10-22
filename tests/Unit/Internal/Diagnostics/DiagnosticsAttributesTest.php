<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Diagnostics;

use AgentCat\Internal\Diagnostics\DiagnosticsAttributes;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\SdkVersion;
use AgentCat\Internal\Support\BridgeConfig;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\JsonAssert;
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
        BridgeConfig::reset();
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

    /**
     * On the wire, so that a null or empty value cannot hide behind the PHPDoc type. `APP_ENV` is unset and the host
     * functions are switched off, so some values are null before the filter.
     */
    public function testEveryValueIsANonEmptyString(): void
    {
        $none = static fn(): bool => false;

        foreach ([DiagnosticsAttributes::resource('proj_x'), DiagnosticsAttributes::resource('', $none), DiagnosticsAttributes::record()] as $attributes) {
            $decoded = Json::decode(Json::encodeJs($attributes) ?? 'null');
            $this->assertIsList($decoded);
            foreach ($decoded as $attribute) {
                $value = JsonAssert::at($attribute, 'value', 'stringValue');
                $this->assertIsString($value, Json::encodeJs($attribute) ?? '');
                $this->assertNotSame('', $value, Json::encodeJs($attribute) ?? '');
            }
        }
    }

    /** @return iterable<string, array{list<string>, list<string>}> */
    public static function disabledFunctions(): iterable
    {
        yield 'php_uname' => [['php_uname'], ['os.version', 'host.arch']];
        yield 'getmypid' => [['getmypid'], ['process.pid']];
        yield 'gethostname' => [['gethostname'], []];
        yield 'all three' => [['php_uname', 'getmypid', 'gethostname'], ['process.pid', 'os.version', 'host.arch']];
    }

    /**
     * A hardened host can list these in `disable_functions`: only their attributes are left out (TS: partial
     * attributes are fine). Without `gethostname()` the install ID hashes an empty host name (TS `?? ""`).
     *
     * @param list<string> $disabled
     * @param list<string> $missing
     */
    #[DataProvider('disabledFunctions')]
    public function testADisabledHostFunctionLeavesOutOnlyItsAttribute(array $disabled, array $missing): void
    {
        $exists = static fn(string $function): bool => !in_array($function, $disabled, true);
        $all = $this->pairs(DiagnosticsAttributes::resource('proj_x'));

        $this->assertSame(array_diff_key($all, array_flip($missing)), $this->pairs(DiagnosticsAttributes::resource('proj_x', $exists)));
        $host = in_array('gethostname', $disabled, true) ? '' : (string) gethostname();
        $installId = $this->pairs(DiagnosticsAttributes::resource('', $exists))['agentcat.install_id'] ?? null;
        $this->assertSame(DiagnosticsAttributes::installId($host, 'file://' . dirname(__DIR__, 4)), $installId);
    }

    public function testAppEnvBecomesTheDeploymentEnvironmentAtTheEnd(): void
    {
        putenv('APP_ENV=staging');

        $pairs = $this->pairs(DiagnosticsAttributes::resource('proj_x'));

        $this->assertSame('deployment.environment', array_key_last($pairs));
        $this->assertSame('staging', $pairs['deployment.environment'] ?? null);
    }

    /** A cached Laravel configuration hides `.env` from the process: Laravel's `app.env` comes first. */
    public function testTheConfiguredAppEnvironmentComesBeforeAppEnv(): void
    {
        putenv('APP_ENV=local');
        BridgeConfig::configure(['APP_ENV' => 'production'], false);

        $this->assertSame('production', $this->pairs(DiagnosticsAttributes::resource('proj_x'))['deployment.environment'] ?? null);
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

    /** Only linux, darwin and win32 are mapped. */
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

    /** Only x64 and arm64 are mapped; any other machine name is sent as is. */
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
