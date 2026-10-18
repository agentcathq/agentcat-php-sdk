<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Transport;

use AgentCat\Internal\Transport\CurlMulti;
use AgentCat\Tests\Integration\Support\MockCollector;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\TestCase;

/** The engine itself; CurlTransportTest and CurlHttpChannelTest cover it through its two callers. */
final class CurlMultiTest extends TestCase
{
    private static ?MockCollector $collector = null;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$collector = MockCollector::start();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::$collector?->stop();
        self::$collector = null;
    }

    /**
     * I2: a signal handler runs between two opcodes, so also inside a PHP write callback of `curl_multi_exec()`. A
     * drain there calls the engine again: libcurl refuses every multi call (CURLM_RECURSIVE_API_CALL), and failing the
     * transfers would free a handle that libcurl still uses (a segfault). The nested calls must return at once and
     * leave the transfers alone. The test keeps every handle alive, so that a regression fails an assertion instead of
     * crashing PHPUnit.
     */
    #[MaximumDuration(3000)]
    public function testACallFromInsideATransferCallbackLeavesTheTransfersAlone(): void
    {
        $url = $this->collector()->scenario([], ['status' => 200, 'body' => str_repeat('x', 200_000)]);
        /** @var CurlMulti<string> $multi */
        $multi = new CurlMulti();
        $finished = [];
        $failed = [];
        $onFinished = static function (string $payload, \CurlHandle $handle, int $result) use (&$finished, $multi): void {
            $finished[] = [$payload, $result, $multi->executing()];
        };
        $onFailed = static function (string $payload, string $error) use (&$failed): void {
            $failed[] = [$payload, $error];
        };
        $handles = [];
        $nested = [];
        $write = static function (\CurlHandle $handle, string $data) use ($multi, $onFinished, $onFailed, &$handles, &$nested): int {
            $handles[spl_object_id($handle)] = $handle;
            if ($nested === []) {
                $begin = hrtime(true);
                $nested['executing'] = $multi->executing();
                $multi->run($onFinished, $onFailed);
                $multi->select(1.0);
                $nested['seconds'] = (hrtime(true) - $begin) / 1e9;
                $nested['count'] = $multi->count();
            }

            return strlen($data);
        };

        $this->assertNull($multi->open([CURLOPT_URL => $url . '/a', CURLOPT_WRITEFUNCTION => $write], 'a'));
        $until = microtime(true) + 2.0;
        while ($multi->count() > 0) {
            $this->assertLessThan($until, microtime(true), 'the transfer did not finish');
            $multi->run($onFinished, $onFailed);
            $multi->select(0.05);
        }

        $this->assertSame([], $failed, 'no transfer failed');
        $this->assertSame([['a', CURLE_OK, false]], $finished, 'the transfer completed once, after curl_multi_exec() returned');
        $this->assertSame(['executing', 'seconds', 'count'], array_keys($nested), 'the write callback ran');
        $this->assertTrue($nested['executing'] ?? null);
        $this->assertLessThan(0.5, $nested['seconds'] ?? INF, 'the nested select returned at once');
        $this->assertSame(1, $nested['count'] ?? null, 'the transfer stayed in the multi handle');
        $this->assertFalse($multi->executing());
    }

    private function collector(): MockCollector
    {
        $this->assertInstanceOf(MockCollector::class, self::$collector);

        return self::$collector;
    }
}
