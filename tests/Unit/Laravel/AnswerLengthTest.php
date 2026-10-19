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

/**
 * D59: the length of a tracked laravel/mcp server's plain answer, on a SAPI that sends a response with the script's end.
 * The rule is `ContentLength`'s, which `Unit\Internal\Delivery\ContentLengthTest` tests: here, the bridge's part.
 */
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

    /** The answer's headers reach the rule of `ContentLength` (`Unit\Internal\Delivery`): a length of its own stays. */
    public function testAnAnswerWithItsOwnLengthKeepsIt(): void
    {
        $response = new Response(self::ANSWER, 200, ['Content-Length' => '7']);

        (new AnswerLength())->handle(new RequestHandled($this->marked(), $response));

        $this->assertSame('7', $response->headers->get('Content-Length'));
    }

    /** The answer's status reaches the rule of `ContentLength`: a 204 has no body and gets no length. */
    public function testAnAnswerWithoutABodyGetsNoLength(): void
    {
        $response = new Response('', 204);

        (new AnswerLength())->handle(new RequestHandled($this->marked(), $response));

        $this->assertFalse($response->headers->has('Content-Length'));
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
