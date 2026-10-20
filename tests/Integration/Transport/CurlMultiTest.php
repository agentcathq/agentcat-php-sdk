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
     * A signal handler runs between two opcodes, so also inside a PHP write callback of
     * `curl_multi_exec()`. There libcurl answers every multi call with `CURLM_RECURSIVE_API_CALL`, and the engine must
     * leave every handle and transfer as it is: removing one there frees a handle that libcurl still uses (a segfault).
     * A refused add waits for the next `run()`. The test keeps every handle alive, so that a regression fails an
     * assertion instead of crashing PHPUnit.
     */
    #[MaximumDuration(5000)]
    public function testACallFromInsideATransferCallbackLeavesEveryTransferAsItIs(): void
    {
        $url = $this->collector()->scenario([], ['status' => 200, 'body' => str_repeat('x', 200_000)]);
        $codes = [];
        /** @var CurlMulti<string> $multi */
        $multi = new CurlMulti(static function (string $function, int $code) use (&$codes): int {
            $codes[] = [$function, $code];

            return $code;
        });
        $finished = [];
        $failed = [];
        $onFinished = static function (string $payload, \CurlHandle $handle, int $result) use (&$finished): void {
            $finished[] = [$payload, $result];
        };
        $onFailed = static function (string $payload, string $error) use (&$failed): void {
            $failed[] = [$payload, $error];
        };
        $handles = [];
        $nested = [];
        $write = static function (\CurlHandle $handle, string $data) use ($multi, $url, $onFinished, $onFailed, &$handles, &$nested): int {
            $handles[spl_object_id($handle)] = $handle;
            if ($nested === []) {
                $begin = hrtime(true);
                $nested['run'] = $multi->run($onFinished, $onFailed);
                $multi->select(3.0);
                $nested['open'] = $multi->open([CURLOPT_URL => $url . '/b', CURLOPT_RETURNTRANSFER => true], 'b');
                $nested['seconds'] = (hrtime(true) - $begin) / 1e9;
                $nested['count'] = $multi->count();
            }

            return strlen($data);
        };

        $this->assertNull($multi->open([CURLOPT_URL => $url . '/a', CURLOPT_WRITEFUNCTION => $write], 'a'));
        $this->drain($multi, $onFinished, $onFailed);

        $this->assertSame([], $failed, 'no transfer failed');
        sort($finished);
        $this->assertSame([['a', CURLE_OK], ['b', CURLE_OK]], $finished, 'both completed, once each');
        $this->assertSame(['run', 'open', 'seconds', 'count'], array_keys($nested), 'the write callback ran');
        $this->assertFalse($nested['run'] ?? null, 'run() reports the refusal');
        $this->assertArrayHasKey('open', $nested);
        $this->assertNull($nested['open'], 'the add from inside the callback waits, it does not fail');
        $this->assertLessThan(1.5, $nested['seconds'] ?? INF, 'libcurl refused the nested wait at once');
        $this->assertSame(2, $nested['count'] ?? null, 'a stayed, b waits');
        $this->assertContains(['curl_multi_exec', CurlMulti::RECURSIVE_API_CALL], $codes, 'the nested run was refused');
        $this->assertContains(['curl_multi_add_handle', CurlMulti::RECURSIVE_API_CALL], $codes, 'so was the nested add');
        $this->assertCount(2, array_keys($codes, ['curl_multi_add_handle', CURLM_OK], true), 'the next run added b');
        $this->assertSame(0, $multi->count());
    }

    /** PHP runs a signal that arrived during curl_multi_exec() right after it returns; a call from there works as usual. */
    #[MaximumDuration(5000)]
    public function testACallRightAfterCurlMultiExecReturnsWorksAsUsual(): void
    {
        $url = $this->collector()->scenario();
        $finished = [];
        $onFinished = static function (string $payload, \CurlHandle $handle, int $result) use (&$finished): void {
            $finished[] = [$payload, $result];
        };
        $onFailed = static function (string $payload, string $error): never {
            self::fail('no transfer may fail: ' . $error);
        };
        /** @var CurlMulti<string>|null $multi */
        $multi = null;
        $nested = [];
        $multi = new CurlMulti(static function (string $function, int $code) use (&$multi, &$nested, $onFinished, $onFailed): int {
            if ($function === 'curl_multi_exec' && $nested === [] && $multi instanceof CurlMulti) {
                $nested[] = $code;
                $multi->select(0.5);
                $nested[] = $multi->run($onFinished, $onFailed); // the seam runs where the signal would
                $nested[] = $multi->count();
            }

            return $code;
        });

        $this->assertNull($multi->open([CURLOPT_URL => $url . '/a', CURLOPT_RETURNTRANSFER => true], 'a'));
        $this->drain($multi, $onFinished, $onFailed);

        $this->assertSame([['a', CURLE_OK]], $finished);
        $this->assertSame(CURLM_OK, $nested[0] ?? null, 'the outer curl_multi_exec() had returned');
        $this->assertTrue($nested[1] ?? null, 'so the nested run ran');
    }

    /**
     * @param CurlMulti<string>                          $multi
     * @param \Closure(string, \CurlHandle, int): void $finished
     * @param \Closure(string, string): void            $failed
     */
    private function drain(CurlMulti $multi, \Closure $finished, \Closure $failed): void
    {
        $until = microtime(true) + 3.0;
        while ($multi->count() > 0) {
            $this->assertLessThan($until, microtime(true), 'the transfers did not finish');
            $multi->run($finished, $failed);
            $multi->select(0.05);
        }
    }

    private function collector(): MockCollector
    {
        $this->assertInstanceOf(MockCollector::class, self::$collector);

        return self::$collector;
    }
}
