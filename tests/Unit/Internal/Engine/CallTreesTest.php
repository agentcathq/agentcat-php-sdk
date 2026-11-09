<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Engine;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Engine\CallTrees;
use AgentCat\Internal\Engine\HttpContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class CallTreesTest extends TestCase
{
    /** @return iterable<string, array{array<array-key, mixed>|null, ?string}> */
    public static function claims(): iterable
    {
        yield 'a version' => [[Constants::META_PROTOCOL_VERSION_KEY => '2026-07-28'], '2026-07-28'];
        yield 'an empty string' => [[Constants::META_PROTOCOL_VERSION_KEY => ''], null];
        yield 'a number' => [[Constants::META_PROTOCOL_VERSION_KEY => 20260728], null];
        yield 'no claim' => [['progressToken' => 'p'], null];
        yield 'no _meta' => [null, null];
    }

    /**
     * Only what the call itself claims, as a non-empty string: never the version the session negotiated.
     *
     * @param array<array-key, mixed>|null $meta
     */
    #[DataProvider('claims')]
    public function testTheClaimedVersionIsANonEmptyStringInTheMeta(?array $meta, ?string $expected): void
    {
        $this->assertSame($expected, CallTrees::claimedVersion($meta));
    }

    /** @return iterable<string, array{array<array-key, mixed>|\stdClass}> */
    public static function requestParams(): iterable
    {
        $params = ['name' => 'echo', 'arguments' => ['old' => true], 'extra' => [1, 2]];

        yield 'array params' => [$params];
        yield 'object params' => [(object) $params];
    }

    /** @param array<array-key, mixed>|\stdClass $params */
    #[DataProvider('requestParams')]
    public function testTheRequestTreeHasTheRawArgumentsAndTheMetaAsObjects(array|\stdClass $params): void
    {
        $before = $this->json($params);
        $tree = CallTrees::request($params, ['msg' => 'hi', 'session_id' => 'start', 'object' => new \stdClass(), 'list' => []], ['progressToken' => 'p1']);

        $this->assertSame('{"method":"tools/call","params":{"name":"echo","arguments":{"msg":"hi","session_id":"start","object":{},"list":[]},"extra":[1,2],"_meta":{"progressToken":"p1"}}}', $this->json($tree));
        $this->assertSame($before, $this->json($params));
    }

    #[Group('ac-02-47')]
    public function testEmptyArgumentsAndAnEmptyMetaAreObjectsAndIntegerKeysStayKeys(): void
    {
        $this->assertSame('{"method":"tools/call","params":{"name":"echo","arguments":{},"_meta":{}}}', $this->json(CallTrees::request(['name' => 'echo'], [], [])));
        $this->assertSame('{"method":"tools/call","params":{"arguments":{"0":"a","b":1}}}', $this->json(CallTrees::request([], [0 => 'a', 'b' => 1], null)));
    }

    public function testTheExtraTreeHasTheD34KeysInOrder(): void
    {
        $http = new HttpContext('POST', 'http://x/mcp', ['x-agentcat-health-run' => 'S']);
        $auth = (object) ['scopes' => ['read'], 'claims' => new \stdClass()];

        $extra = CallTrees::extra('mcp-session', 7, ['progressToken' => 'p1'], $http, $auth);

        $this->assertSame('{"sessionId":"mcp-session","requestId":7,"_meta":{"progressToken":"p1"},"requestInfo":{"headers":{"x-agentcat-health-run":"S"},"url":"http://x/mcp"},"authInfo":{"scopes":["read"],"claims":{}}}', $this->json($extra));
    }

    public function testTheExtraTreeLeavesOutWhatTheCallDoesNotHave(): void
    {
        $this->assertSame('{"requestId":"r1"}', $this->json(CallTrees::extra(null, 'r1', null, null)));
        $this->assertSame('{"requestId":1,"_meta":{}}', $this->json(CallTrees::extra(null, 1, [], null)));
    }

    public function testAValueJsonCannotCarryThrows(): void
    {
        $this->expectException(\JsonException::class);

        CallTrees::request([], ['n' => NAN], null);
    }

    private function json(mixed $tree): string
    {
        return json_encode($tree, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
