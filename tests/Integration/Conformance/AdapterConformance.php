<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Conformance;

use AgentCat\Event;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Tools\GetMoreTools;
use AgentCat\Options;
use AgentCat\Tests\Support\JsonAssert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The adapter conformance suite (README §8 M10): the same calls and the same expected events on every MCP library
 * AgentCat supports. A library leg implements the factories; the library's own tests cover what only it can do.
 *
 * @phpstan-require-extends TestCase
 */
trait AdapterConformance
{
    private const string SESSION = 'ses_ccccccccccccccccccccccccccc';

    private const string ALREADY_TRACKED = '[SESSION DEBUG] track() - Server already being tracked, skipping initialization';

    /**
     * `conformance-server` 2.0.0 with the tools `echo`, `structured`, `refuses`, `boom` and `own`, tracked with
     * `$options`, after the library's handshake as `harness-client` 1.0.0. `$fresh` means a new server instance per
     * request (the 2026 per-request case), without a handshake.
     */
    abstract protected function conformance(Options $options = new Options(), bool $fresh = false): ConformanceHarness;

    /**
     * The conformance server, tracked by `Client::track()` after another one that it had tracked with
     * `enableTracing: false` was freed: the new server has the object ID of the old one, which the leg asserts.
     */
    abstract protected function reusedObjectId(): ConformanceHarness;

    /**
     * A server whose listing is one full page: 15 tools (`echo`, then `t01` to `t14`), laravel/mcp's default page
     * size, and a page size of 15.
     */
    abstract protected function fullPage(): ConformanceHarness;

    /**
     * A server whose listing has two pages of 15: `echo` and `t01` to `t14` on page 1, then `own` (its own required
     * `session_id`) alone on page 2.
     */
    abstract protected function paged(): ConformanceHarness;

    /**
     * The `parameters.extra.requestInfo` that the leg records for one `tools/call` of `echo` (a supplied session) that
     * a web server hands to PHP with these server variables. The mcp/sdk adapter reads the SAPI's variables, so its
     * leg reads them as `fpm-fcgi` would give them; a leg whose library takes a request object builds it from them.
     *
     * @param array<string, string> $server
     */
    abstract protected function requestInfoOf(array $server): \stdClass;

    /**
     * Two authenticated requests to `/mcp/?b=2&a=1`: Basic as PHP-FPM passes it (with `PHP_AUTH_USER` and
     * `PHP_AUTH_PW`), and Digest as php-cgi behind a rewrite passes it (without `PHP_AUTH_DIGEST`).
     *
     * @return iterable<string, array{array<string, string>}>
     */
    public static function authenticatedRequests(): iterable
    {
        $request = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/mcp/?b=2&a=1', 'QUERY_STRING' => 'b=2&a=1', 'SCRIPT_NAME' => '/index.php', 'SERVER_NAME' => 'example.test', 'SERVER_PORT' => '80', 'HTTP_HOST' => 'example.test'];
        yield 'Basic' => [$request + ['HTTP_AUTHORIZATION' => 'Basic dTpzZWNyZXQ=', 'CONTENT_TYPE' => 'application/json', 'PHP_AUTH_USER' => 'u', 'PHP_AUTH_PW' => 'secret']];
        yield 'Digest' => [$request + ['HTTP_AUTHORIZATION' => 'Digest username="u", realm="r", nonce="n", uri="/mcp/?b=2&a=1", response="x"', 'CONTENT_TYPE' => 'application/json']];
    }

    #[Group('ac-02-6')]
    public function testConformanceListing(): void
    {
        $tools = $this->listedTools($this->conformance()->listTools());

        $this->assertSame('echo', JsonAssert::at($tools, 0, 'name'));
        $this->assertSame(['msg', 'session_id', 'context'], $this->memberNames(JsonAssert::at($tools, 0, 'inputSchema', 'properties')));
        $this->assertSame(['msg', 'session_id', 'context'], JsonAssert::at($tools, 0, 'inputSchema', 'required'));
        $this->assertSame(GetMoreTools::NAME, JsonAssert::at(end($tools), 'name'));
    }

    #[Group('ac-02-15')]
    public function testConformanceListingPublishesNothing(): void
    {
        $harness = $this->conformance();
        $harness->listTools();

        $this->assertSame([], $harness->events());
    }

    #[Group('ac-02-16')]
    #[Group('ac-02-47')]
    public function testConformanceCall(): void
    {
        $harness = $this->conformance();
        $harness->listTools();

        $answer = $harness->callTool('echo', ['msg' => 'hi', 'context' => 'why', 'session_id' => self::SESSION]);

        $this->assertSame('echo: hi', JsonAssert::at($answer, 'result', 'content', 0, 'text'));
        $this->assertSame([['msg' => 'hi']], $harness->received('echo'));
        $event = $this->onlyEvent($harness);
        $this->assertSame(['echo', 'why', self::SESSION], [$event->resourceName, $event->userIntent, $event->sessionId]);
        $this->assertSame('supplied', $event->tags[Constants::TAG_SESSION_SOURCE] ?? null);
        $this->assertSame(['conformance-server', '2.0.0'], [$event->serverName, $event->serverVersion]);
        $this->assertEquals((object) ['msg' => 'hi', 'context' => 'why', 'session_id' => self::SESSION], JsonAssert::at($event->parameters, 'request', 'params', 'arguments'));
    }

    #[Group('ac-02-19')]
    public function testConformanceOwnSessionParameter(): void
    {
        $harness = $this->conformance();
        $harness->listTools();

        $harness->callTool('own', ['session_id' => 'theirs']);

        $event = $this->onlyEvent($harness);
        $this->assertSame([['session_id' => 'theirs']], $harness->received('own'));
        $this->assertSame('', $event->sessionId);
        $this->assertSame('foreign', $event->tags[Constants::TAG_SESSION_SOURCE] ?? null);
    }

    #[Group('ac-02-20')]
    public function testConformanceFreshInstance(): void
    {
        $harness = $this->conformance(fresh: true);

        $harness->callTool('echo', ['msg' => 'hi', 'context' => 'why', 'session_id' => 'start']);

        $this->assertSame([['msg' => 'hi']], $harness->received('echo'));
        $this->assertContains('Rebuilt injection registries on demand (tools/call before tools/list on this instance)', $harness->lines());
    }

    #[Group('ac-02-23')]
    public function testConformanceErrorResult(): void
    {
        $harness = $this->conformance();
        $harness->listTools();

        $harness->callTool('refuses', ['session_id' => self::SESSION]);

        $event = $this->onlyEvent($harness);
        $this->assertTrue($event->isError);
        $this->assertSame('not allowed', JsonAssert::at($event->error, 'message'));
    }

    #[Group('ac-02-24')]
    public function testConformanceThrownException(): void
    {
        $harness = $this->conformance();
        $harness->listTools();

        $harness->callTool('boom', ['session_id' => self::SESSION]);

        $event = $this->onlyEvent($harness);
        $this->assertTrue($event->isError);
        $this->assertSame(['RuntimeException', 'tool boom'], [JsonAssert::at($event->error, 'type'), JsonAssert::at($event->error, 'message')]);
    }

    #[Group('ac-02-26')]
    public function testConformanceUnknownTool(): void
    {
        $harness = $this->conformance();
        $harness->listTools();

        $harness->callTool('nope', ['session_id' => self::SESSION]);

        $event = $this->onlyEvent($harness);
        $this->assertSame(['nope', true], [$event->resourceName, $event->isError]);
    }

    #[Group('ac-02-30')]
    public function testConformanceMintBack(): void
    {
        $harness = $this->conformance();
        $harness->listTools();

        $answer = $harness->callTool('echo', ['msg' => 'hi', 'context' => 'why']);

        $event = $this->onlyEvent($harness);
        $this->assertSame(Constants::MINT_BACK_HEADER_ISSUED . "\nsession_id: " . $event->sessionId . "\n" . Constants::MINT_BACK_ISSUED_BODY, JsonAssert::at($answer, 'result', 'content', 0, 'text'));
        $this->assertSame('minted', $event->tags[Constants::TAG_SESSION_SOURCE] ?? null);
    }

    #[Group('ac-02-36')]
    public function testConformanceStructuredMirror(): void
    {
        $harness = $this->conformance();
        $harness->listTools();

        $answer = $harness->callTool('structured', ['session_id' => 'start', 'context' => 'why']);

        $this->assertSame([Constants::MCP_SESSION_KEY, 'echoed'], $this->memberNames(JsonAssert::at($answer, 'result', 'structuredContent')));
        $event = $this->onlyEvent($harness);
        $this->assertEquals((object) ['echoed' => 'x'], JsonAssert::at($event->response, 'structuredContent'));
    }

    #[Group('ac-02-40')]
    public function testConformanceGetMoreTools(): void
    {
        $harness = $this->conformance();
        $harness->listTools();

        $answer = $harness->callTool(GetMoreTools::NAME, ['context' => 'need X', 'session_id' => self::SESSION]);

        $content = JsonAssert::at($answer, 'result', 'content');
        $this->assertIsList($content);
        $this->assertSame('Unfortunately, we have shown you the full tool list. We have noted your feedback and will work to improve the tool list in the future.', JsonAssert::at(end($content), 'text'));
        $this->assertContains('Missing tool reported (context length: 6)', $harness->lines());
    }

    #[Group('ac-02-44')]
    #[Group('ac-02-45')]
    public function testConformanceClientAndProtocolFromMeta(): void
    {
        $harness = $this->conformance();
        $harness->listTools();

        $harness->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION], [Constants::META_CLIENT_INFO_KEY => ['name' => 'meta-client', 'version' => '3']]);

        $event = $this->onlyEvent($harness);
        $this->assertSame(['meta-client', '3'], [$event->clientName, $event->clientVersion]);
        $this->assertSame('2026-07-28', $event->tags[Constants::TAG_PROTOCOL_VERSION] ?? null);
    }

    /** A call without `_meta` names the client of the instance's handshake, whose protocol version is no tag. */
    #[Group('ac-02-44')]
    #[Group('ac-02-45')]
    public function testConformanceClientFromTheHandshake(): void
    {
        $harness = $this->conformance();
        $harness->listTools();

        $harness->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);

        $event = $this->onlyEvent($harness);
        $this->assertSame(['harness-client', '1.0.0'], [$event->clientName, $event->clientVersion]);
        $this->assertArrayNotHasKey(Constants::TAG_PROTOCOL_VERSION, $event->tags ?? []);
    }

    #[Group('ac-02-52')]
    public function testConformanceTracingOff(): void
    {
        $harness = $this->conformance(new Options(enableTracing: false));
        $tools = $this->listedTools($harness->listTools());

        $harness->callTool('echo', ['msg' => 'hi']);

        $this->assertNotContains('session_id', $this->memberNames(JsonAssert::at($tools, 0, 'inputSchema', 'properties')));
        $this->assertSame([], $harness->events());
    }

    /**
     * The HTTP request as the client sent it (D06, D34): the URL with its own query order and trailing slash, and the
     * headers it sent, `Authorization` included, but none that a library makes up from them.
     *
     * @param array<string, string> $server
     */
    #[DataProvider('authenticatedRequests')]
    #[Group('ac-02-43')]
    public function testConformanceRequestInfo(array $server): void
    {
        $info = $this->requestInfoOf($server);

        $this->assertSame('http://example.test/mcp/?b=2&a=1', JsonAssert::at($info, 'url'));
        $this->assertSame(['host', 'authorization', 'content-type'], $this->memberNames(JsonAssert::at($info, 'headers')));
        $this->assertSame($server['HTTP_AUTHORIZATION'] ?? null, JsonAssert::at($info, 'headers', 'authorization'));
    }

    /** Plan E1 header conflict 3: a reused object ID belongs to a dead server, so the new server's state replaces it. */
    #[Group('ac-02-5')]
    public function testConformanceReusedObjectId(): void
    {
        $harness = $this->reusedObjectId();
        $tools = $this->listedTools($harness->listTools());

        $harness->callTool('echo', ['msg' => 'hi', 'context' => 'why', 'session_id' => self::SESSION]);

        $this->assertSame(['msg', 'session_id', 'context'], $this->memberNames(JsonAssert::at($tools, 0, 'inputSchema', 'properties')));
        $this->assertSame([['msg' => 'hi']], $harness->received('echo'));
        $event = $this->onlyEvent($harness);
        $this->assertSame(['echo', 'why', self::SESSION], [$event->resourceName, $event->userIntent, $event->sessionId]);
        $this->assertSame(['conformance-server', '2.0.0'], [$event->serverName, $event->serverVersion]);
        $this->assertNotContains(self::ALREADY_TRACKED, $harness->lines());
    }

    /** Plan E1 header conflict 1: a full page has no `nextCursor`, and `get_more_tools` comes after its 15 tools. */
    #[Group('ac-02-8')]
    public function testConformanceOneFullPage(): void
    {
        $listing = $this->fullPage()->listTools();

        $tools = $this->listedTools($listing);
        $this->assertObjectNotHasProperty('nextCursor', $listing);
        $named = array_map(static fn(int $i): string => sprintf('t%02d', $i), range(1, 14));
        $this->assertSame(['echo', ...$named, GetMoreTools::NAME], array_map(static fn(mixed $tool): mixed => JsonAssert::at($tool, 'name'), $tools));
        $this->assertSame(array_fill(0, 14, ['session_id', 'context']), array_map(fn(mixed $tool): array => $this->memberNames(JsonAssert::at($tool, 'inputSchema', 'properties')), array_slice($tools, 1, 14)));
    }

    /**
     * D66 (B10 fixed): a client lists page 1 only, then calls `own` from page 2. A long-running leg now holds page 1's
     * registries, which do not name `own`, so the call rebuilds to page 2: `own` keeps its own `session_id`.
     */
    #[Group('ac-02-8')]
    #[Group('ac-02-19')]
    public function testConformanceSecondPage(): void
    {
        $harness = $this->paged();
        $first = $harness->listTools();

        $harness->callTool('own', ['session_id' => 'theirs']);

        $names = array_map(static fn(mixed $tool): mixed => JsonAssert::at($tool, 'name'), $this->listedTools($first));
        $this->assertSame(['echo', ...array_map(static fn(int $i): string => sprintf('t%02d', $i), range(1, 14))], $names);
        $this->assertObjectHasProperty('nextCursor', $first);
        $this->assertSame([['session_id' => 'theirs']], $harness->received('own'));
        $this->assertSame('foreign', $this->onlyEvent($harness)->tags[Constants::TAG_SESSION_SOURCE] ?? null);
    }

    /** The library's validation of the tool's own arguments fails: the tool does not run, and the event records it. */
    #[Group('ac-05-E6')]
    public function testConformanceValidationError(): void
    {
        $harness = $this->conformance();
        $harness->listTools();

        $harness->callTool('echo', ['context' => 'why', 'session_id' => self::SESSION]);

        $this->assertSame([], $harness->received('echo'));
        $event = $this->onlyEvent($harness);
        $this->assertSame(['echo', true, 'why', self::SESSION], [$event->resourceName, $event->isError, $event->userIntent, $event->sessionId]);
        $message = JsonAssert::at($event->error, 'message');
        $this->assertIsString($message);
        $this->assertStringContainsString('msg', $message);
    }

    /** Each call of a long-lived server sees and records only its own arguments. */
    #[Group('ac-02-51')]
    public function testConformanceConsecutiveCalls(): void
    {
        $harness = $this->conformance();
        $harness->listTools();

        $harness->callTool('echo', ['msg' => 'one', 'context' => 'first', 'session_id' => self::SESSION]);
        $answer = $harness->callTool('echo', ['msg' => 'two', 'session_id' => self::SESSION]);

        $this->assertSame('echo: two', JsonAssert::at($answer, 'result', 'content', 0, 'text'));
        $this->assertSame([['msg' => 'one'], ['msg' => 'two']], $harness->received('echo'));
        $events = $harness->events();
        $this->assertCount(2, $events);
        [$first, $second] = $events;
        $this->assertSame(['first', null], [$first->userIntent, $second->userIntent]);
        $this->assertEquals((object) ['msg' => 'one', 'context' => 'first', 'session_id' => self::SESSION], JsonAssert::at($first->parameters, 'request', 'params', 'arguments'));
        $this->assertEquals((object) ['msg' => 'two', 'session_id' => self::SESSION], JsonAssert::at($second->parameters, 'request', 'params', 'arguments'));
    }

    /** The one event the harness delivered; fails unless it delivered exactly one. */
    private function onlyEvent(ConformanceHarness $harness): Event
    {
        $events = $harness->events();
        $this->assertCount(1, $events);
        [$event] = $events;

        return $event;
    }

    /** @return list<mixed> the listing's `tools` */
    private function listedTools(\stdClass $listing): array
    {
        $tools = JsonAssert::at($listing, 'tools');
        $this->assertIsList($tools);

        return $tools;
    }

    /** @return list<string> the member names of a JSON object, in order */
    private function memberNames(mixed $object): array
    {
        $this->assertInstanceOf(\stdClass::class, $object);

        return array_map(strval(...), array_keys(get_object_vars($object)));
    }
}
