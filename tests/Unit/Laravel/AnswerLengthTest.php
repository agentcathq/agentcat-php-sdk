<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Laravel;

use AgentCat\Laravel\Internal\AnswerLength;
use AgentCat\Tests\Support\LaravelMcp\HeaderIdentityServer;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Mcp\Server\Transport\HttpTransport;
use Laravel\Mcp\Server\Transport\StdioTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** D59: the length of a tracked laravel/mcp server's plain answer, on a SAPI that sends a response with the script's end. */
#[Group('ac-03-1')]
final class AnswerLengthTest extends TestCase
{
    private const string ANSWER = '{"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":"café"}]}}';

    public function testATrackedServersPlainAnswerGetsItsLengthInBytes(): void
    {
        $response = new Response(self::ANSWER, 200, ['Content-Type' => 'application/json']);

        (new AnswerLength())->handle(new RequestHandled($this->marked(), $response));

        $this->assertSame('78', $response->headers->get('Content-Length'), 'bytes, not characters: é is two');
    }

    /** laravel/mcp answers a notification with 202 and no body. */
    public function testAnEmptyAnswerGetsALengthOfZero(): void
    {
        $response = new Response('', 202);

        (new AnswerLength())->handle(new RequestHandled($this->marked(), $response));

        $this->assertSame('0', $response->headers->get('Content-Length'));
    }

    /** @return iterable<string, array{Request}> */
    public static function unmarkedRequests(): iterable
    {
        yield 'no tracked server answered (another route, an excluded server, AgentCat off)' => [Request::create('/mcp', 'POST')];
        $other = Request::create('/mcp', 'POST');
        $other->attributes->set(AnswerLength::TRACKED, '1');
        yield 'an attribute that is not the mark' => [$other];
    }

    #[DataProvider('unmarkedRequests')]
    public function testAnAnswerToAnotherRequestStaysAsItIs(Request $request): void
    {
        $response = new Response(self::ANSWER);

        (new AnswerLength())->handle(new RequestHandled($request, $response));

        $this->assertFalse($response->headers->has('Content-Length'));
    }

    /** A streamed answer has no content (`StreamedResponse::getContent()` is false); `Integration\Laravel` sends a real one. */
    public function testAnAnswerWithoutContentStaysAsItIs(): void
    {
        $response = new class extends Response {
            #[\Override]
            public function getContent(): string|false
            {
                return false;
            }
        };

        (new AnswerLength())->handle(new RequestHandled($this->marked(), $response));

        $this->assertFalse($response->headers->has('Content-Length'));
    }

    public function testAnAnswerWithItsOwnLengthKeepsIt(): void
    {
        $response = new Response(self::ANSWER, 200, ['Content-Length' => '7']);

        (new AnswerLength())->handle(new RequestHandled($this->marked(), $response));

        $this->assertSame('7', $response->headers->get('Content-Length'));
    }

    public function testAnAnswerWithATransferEncodingGetsNoLength(): void
    {
        $response = new Response(self::ANSWER, 200, ['Transfer-Encoding' => 'chunked']);

        (new AnswerLength())->handle(new RequestHandled($this->marked(), $response));

        $this->assertFalse($response->headers->has('Content-Length'));
        $this->assertSame('chunked', $response->headers->get('Transfer-Encoding'));
    }

    /** D32: the listener runs inside Laravel's HTTP kernel, so nothing it meets may break the request. */
    public function testAResponseThatFailsLeavesTheRequestAlone(): void
    {
        $response = new class (self::ANSWER) extends Response {
            #[\Override]
            public function getContent(): string|false
            {
                throw new \RuntimeException('no content');
            }
        };

        (new AnswerLength())->handle(new RequestHandled($this->marked(), $response));

        $this->assertFalse($response->headers->has('Content-Length'));
    }

    /** @return iterable<string, array{list<string>}> the bytes in each output buffer, from the bottom level up */
    public static function strayOutput(): iterable
    {
        yield 'a BOM in the top buffer' => [["\xEF\xBB\xBF"]];
        yield 'a newline after a closing tag, in a lower buffer' => [["\n", '']];
    }

    /**
     * Bytes written before the answer wait in an output buffer (mod_php and php-cgi with `output_buffering`) and go out
     * first: a length of the answer alone would cut the end of the JSON. The answer stays as laravel/mcp sends it.
     *
     * @param list<string> $buffers
     */
    #[DataProvider('strayOutput')]
    public function testStrayOutputInAnOutputBufferLeavesTheAnswerAlone(array $buffers): void
    {
        $response = new Response(self::ANSWER);
        $level = ob_get_level();
        foreach ($buffers as $bytes) {
            ob_start();
            echo $bytes;
        }

        try {
            (new AnswerLength())->handle(new RequestHandled($this->marked(), $response));
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        $this->assertFalse($response->headers->has('Content-Length'));
    }

    /** An empty output buffer holds nothing that goes out before the answer. */
    public function testAnEmptyOutputBufferDoesNotCount(): void
    {
        $response = new Response(self::ANSWER);
        ob_start();

        try {
            (new AnswerLength())->handle(new RequestHandled($this->marked(), $response));
        } finally {
            ob_end_clean();
        }

        $this->assertSame('78', $response->headers->get('Content-Length'));
    }

    /** Headers that went out already (stray output without a buffer) cannot carry a length any more. */
    public function testAnAnswerAfterTheHeadersWentOutStaysAsItIs(): void
    {
        $response = new Response(self::ANSWER);

        (new AnswerLength(static fn(): bool => true))->handle(new RequestHandled($this->marked(), $response));

        $this->assertFalse($response->headers->has('Content-Length'));
    }

    public function testATrackedServerMarksTheRequestItAnswersOverHttp(): void
    {
        $request = Request::create('/mcp', 'POST');

        AnswerLength::mark(new HeaderIdentityServer(new HttpTransport($request)));

        $this->assertTrue($request->attributes->get(AnswerLength::TRACKED));
    }

    /** A stdio server answers no HTTP request: there is nothing to mark, and nothing fails. */
    public function testAStdioServerMarksNothing(): void
    {
        $this->expectNotToPerformAssertions();

        AnswerLength::mark(new HeaderIdentityServer(new StdioTransport()));
    }

    private function marked(): Request
    {
        $request = Request::create('/mcp', 'POST');
        $request->attributes->set(AnswerLength::TRACKED, true);

        return $request;
    }
}
