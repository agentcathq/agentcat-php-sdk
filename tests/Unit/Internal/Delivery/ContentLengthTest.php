<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Delivery;

use AgentCat\Internal\Delivery\ContentLength;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * D59, D62: the length that a tracked server's plain answer gets on a SAPI that sends a response only when the script
 * ends, one rule for the Laravel bridge and the Symfony bundle.
 */
#[Group('ac-03-1')]
final class ContentLengthTest extends TestCase
{
    private const string ANSWER = '{"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":"café"}]}}';

    public function testAPlainAnswerGetsItsLengthInBytes(): void
    {
        $this->assertSame('78', (new ContentLength())->of(['content-type' => ['application/json']], self::ANSWER), 'bytes, not characters: é is two');
    }

    /** laravel/mcp and mcp/sdk answer a notification with 202 and no body. */
    public function testAnEmptyAnswerGetsALengthOfZero(): void
    {
        $this->assertSame('0', (new ContentLength())->of([], ''));
    }

    /** A streamed answer (SSE) has no content: `StreamedResponse::getContent()` is false. */
    public function testAStreamedAnswerGetsNoLength(): void
    {
        $this->assertNull((new ContentLength())->of(['content-type' => ['text/event-stream']], false));
    }

    /** @return iterable<string, array{array<array-key, mixed>}> the headers of the answer, as `HeaderBag::all()` gives them */
    public static function framedAnswers(): iterable
    {
        yield 'its own Content-Length' => [['content-length' => ['7']]];
        yield 'a Transfer-Encoding' => [['transfer-encoding' => ['chunked']]];
        yield 'a Content-Length named in another case' => [['Content-Length' => ['7']]];
        yield 'a Transfer-Encoding named in another case' => [['Transfer-Encoding' => ['chunked']]];
    }

    /**
     * An answer that says itself where it ends keeps what it says.
     *
     * @param array<array-key, mixed> $headers
     */
    #[DataProvider('framedAnswers')]
    public function testAnAnswerThatFramesItselfGetsNoLength(array $headers): void
    {
        $this->assertNull((new ContentLength())->of(['content-type' => ['application/json'], ...$headers], self::ANSWER));
    }

    /** @return iterable<string, array{list<string>}> the bytes in each output buffer, from the bottom level up */
    public static function strayOutput(): iterable
    {
        yield 'a BOM in the top buffer' => [["\xEF\xBB\xBF"]];
        yield 'a newline after a closing tag, in a lower buffer' => [["\n", '']];
    }

    /**
     * Bytes written before the answer wait in an output buffer (mod_php and php-cgi with `output_buffering`) and go out
     * first: a length of the answer alone would cut the end of the JSON.
     *
     * @param list<string> $buffers
     */
    #[DataProvider('strayOutput')]
    public function testStrayOutputInAnOutputBufferLeavesTheAnswerAlone(array $buffers): void
    {
        $level = ob_get_level();
        foreach ($buffers as $bytes) {
            ob_start();
            echo $bytes;
        }

        try {
            $length = (new ContentLength())->of([], self::ANSWER);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        $this->assertNull($length);
    }

    /** An empty output buffer holds nothing that goes out before the answer. */
    public function testAnEmptyOutputBufferDoesNotCount(): void
    {
        ob_start();

        try {
            $length = (new ContentLength())->of([], self::ANSWER);
        } finally {
            ob_end_clean();
        }

        $this->assertSame('78', $length);
    }

    /** Headers that went out already (stray output without a buffer) cannot carry a length any more. */
    public function testAnAnswerAfterTheHeadersWentOutGetsNoLength(): void
    {
        $this->assertNull((new ContentLength(static fn(): bool => true))->of([], self::ANSWER));
    }
}
