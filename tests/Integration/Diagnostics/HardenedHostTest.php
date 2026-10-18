<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Diagnostics;

use AgentCat\Internal\Diagnostics\DiagnosticsAttributes;
use AgentCat\Internal\Json\Json;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\JsonAssert;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** `getmypid()`, `php_uname()` and `gethostname()` are often in a hardened host's `disable_functions`. */
final class HardenedHostTest extends TestCase
{
    /** HOME of the script (its agentcat.log) and its result file, under the test HOME. */
    private string $dir = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = getenv('HOME') . '/hardened-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($this->dir, 0o700));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $names = scandir($this->dir);
        foreach ($names === false ? [] : $names as $name) {
            if (is_file($this->dir . '/' . $name)) {
                unlink($this->dir . '/' . $name);
            }
        }
        rmdir($this->dir);
    }

    #[MaximumDuration(5000)]
    public function testDiagnosticsStartWithoutTheAttributesOfDisabledFunctions(): void
    {
        $result = $this->dir . '/result.json';
        $collector = MockCollector::closedPortUrl();
        $process = new Process(
            [PHP_BINARY, '-d', 'disable_functions=php_uname,getmypid,gethostname', __DIR__ . '/../Fixtures/diagnostics-hardened.php', $result],
            null,
            // The script records its requests; a closed port in case it ever sends one.
            ['HOME' => $this->dir, 'DIAGNOSTICS_ENDPOINT' => $collector, 'DISABLE_DIAGNOSTICS' => false, 'DIAGNOSTICS_TOKEN' => false, 'APP_ENV' => false],
        );
        $process->setTimeout(10);

        $process->run();

        $this->assertSame('', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
        $this->assertSame(0, $process->getExitCode());
        $observed = Json::decode((string) file_get_contents($result));
        $this->assertTrue(JsonAssert::at($observed, 'enabled'), 'diagnostics started');
        $this->assertSame([$collector . '/v1/logs'], JsonAssert::at($observed, 'urls'));
        $body = JsonAssert::at($observed, 'body');
        $this->assertIsString($body);
        $attributes = JsonAssert::at(Json::decode($body), 'resourceLogs', 0, 'resource', 'attributes');
        $this->assertIsList($attributes);
        $pairs = [];
        foreach ($attributes as $attribute) {
            $key = JsonAssert::at($attribute, 'key');
            $this->assertIsString($key);
            $pairs[$key] = JsonAssert::at($attribute, 'value', 'stringValue');
        }
        $this->assertSame(['agentcat.install_id', 'agentcat.sdk.language', 'agentcat.sdk.version', 'agentcat.mcp_sdk.version', 'process.runtime.name', 'process.runtime.version', 'os.type'], array_keys($pairs));
        $this->assertSame(DiagnosticsAttributes::installId('', 'file://' . dirname(__DIR__, 3)), $pairs['agentcat.install_id'] ?? null, 'an empty host name');
        $record = JsonAssert::at(Json::decode($body), 'resourceLogs', 0, 'scopeLogs', 0, 'logRecords', 0, 'body', 'stringValue');
        $this->assertIsString($record);
        $this->assertStringEndsWith('] Warning: hardened', $record);
    }
}
