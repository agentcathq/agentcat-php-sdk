<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Delivery;

use AgentCat\Internal\Json\Json;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** D32: a PHP without ext-curl's functions keeps the facade silent and harmless; events are dropped with one line. */
final class MissingCurlTest extends TestCase
{
    private const string NO_CURL = 'WARN: AgentCat cannot send events, telemetry exports or diagnostics: ext-curl functions are missing or disabled (';

    /** HOME of the script (its agentcat.log) and its result file. */
    private string $dir = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/agentcat-missing-curl-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($this->dir, 0o700));
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $names = scandir($this->dir);
        foreach ($names === false ? [] : $names as $name) {
            if (is_file($this->dir . '/' . $name)) {
                unlink($this->dir . '/' . $name);
            }
        }
        rmdir($this->dir);
    }

    /**
     * Every curl function except `curl_version()`, which the SDK never calls: before PHP 8.4 the vendor autoloader
     * itself calls it (symfony/polyfill-php84, a Laravel dependency), so no script could start without it.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function disabled(): iterable
    {
        $curl = get_extension_funcs('curl');
        yield 'curl_multi_init' => [['curl_multi_init']];
        yield 'every curl function' => [array_values(array_diff($curl === false ? [] : $curl, ['curl_version']))];
    }

    /** @param list<string> $functions */
    #[DataProvider('disabled')]
    #[Group('ac-01-87')]
    #[MaximumDuration(5000)]
    public function testTrackFlushAndShutdownReturnNormallyAndPrintNothing(array $functions): void
    {
        $this->assertNotSame([], $functions, 'ext-curl is loaded in the test process');
        $result = $this->dir . '/result.json';
        $process = new Process(
            [PHP_BINARY, '-d', 'disable_functions=' . implode(',', $functions), __DIR__ . '/../Fixtures/missing-curl.php', $result],
            null,
            // Nothing may reach the network, even if the transport were to send.
            ['HOME' => $this->dir, 'AGENTCAT_API_URL' => 'http://127.0.0.1:9', 'AGENTCAT_ENABLED' => false, 'APP_ENV' => false],
        );
        $process->setTimeout(10);

        $process->run();

        $this->assertSame('', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
        $this->assertSame(0, $process->getExitCode());
        $observed = Json::decode((string) file_get_contents($result));
        $this->assertInstanceOf(\stdClass::class, $observed);
        $this->assertTrue($observed->same);
        $this->assertTrue($observed->flushed);
        $this->assertEquals((object) ['content' => [(object) ['type' => 'text', 'text' => 'echo: hi']], 'isError' => false], $observed->answer);
        $log = (string) file_get_contents($this->dir . '/agentcat.log');
        $this->assertSame(1, substr_count($log, self::NO_CURL), $log);
        $this->assertStringContainsString('AgentCat setup complete', $log);
    }
}
