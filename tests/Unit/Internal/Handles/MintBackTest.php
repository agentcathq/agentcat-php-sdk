<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Handles;

use AgentCat\Internal\Handles\HandleResolution;
use AgentCat\Internal\Handles\MintBack;
use AgentCat\Internal\Handles\SessionIdSource;
use AgentCat\Internal\Json\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class MintBackTest extends TestCase
{
    private function obj(string $json): \stdClass
    {
        $value = Json::decode($json);
        $this->assertInstanceOf(\stdClass::class, $value);

        return $value;
    }

    private function enc(mixed $value): string
    {
        return (string) Json::encodeJs($value);
    }

    #[Group('ac-04-35')]
    #[Group('ac-04-36')]
    public function testPrependTextPutsTheBlockFirstAndLeavesTheInputAlone(): void
    {
        $result = $this->obj('{"content":[{"type":"text","text":"hi"}],"isError":true}');
        $before = $this->enc($result);

        $out = MintBack::prependText($result, 'BLOCK');

        $this->assertSame('{"content":[{"type":"text","text":"BLOCK"},{"type":"text","text":"hi"}],"isError":true}', $this->enc($out));
        $this->assertSame($before, $this->enc($result));
        $this->assertNotSame($result, $out);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function resultsWithoutContentList(): iterable
    {
        yield 'string' => ['{"content":"x"}'];
        yield 'missing' => ['{"isError":false}'];
        yield 'object' => ['{"content":{}}'];
        yield 'null' => ['{"content":null}'];
    }

    #[Group('ac-04-36')]
    #[DataProvider('resultsWithoutContentList')]
    public function testPrependTextIgnoresResultsWithoutAContentList(string $json): void
    {
        $result = $this->obj($json);

        $this->assertSame($result, MintBack::prependText($result, 'BLOCK'));
    }

    #[Group('ac-04-36')]
    public function testPrependTextIgnoresNonObjectResults(): void
    {
        $this->assertNull(MintBack::prependText(null, 'BLOCK'));
        $this->assertSame('x', MintBack::prependText('x', 'BLOCK'));
        $this->assertSame([], MintBack::prependText([], 'BLOCK'));
    }

    #[Group('ac-04-38')]
    public function testNoTextForSuppliedForeignOrHookMode(): void
    {
        $this->assertNull(MintBack::text(new HandleResolution('ses_A', SessionIdSource::Supplied)));
        $this->assertNull(MintBack::text(new HandleResolution('', SessionIdSource::Foreign)));
        $this->assertNull(MintBack::text(new HandleResolution('ses_A', SessionIdSource::Minted, null, true)));
        $this->assertNull(MintBack::text(new HandleResolution('', SessionIdSource::Hook, null, true)));
        $this->assertNull(MintBack::text(new HandleResolution('', SessionIdSource::Invalid, null, true)));
    }

    #[Group('ac-04-35')]
    public function testTextHasLfLinesAndNoTrailingNewline(): void
    {
        $text = (string) MintBack::text(new HandleResolution('ses_A', SessionIdSource::Minted, 'agent'));

        $this->assertSame(2, substr_count($text, "\n"));
        $this->assertStringNotContainsString("\r", $text);
        $this->assertStringContainsString("\nsession_id: ses_A\n", $text);
        $this->assertStringNotContainsString('agent', $text);
        $this->assertStringEndsNotWith("\n", $text);
    }

    /**
     * @return iterable<string, array{HandleResolution, ?string}>
     */
    public static function payloadStates(): iterable
    {
        yield 'minted' => [new HandleResolution('ses_A', SessionIdSource::Minted), '{"session_id":"ses_A","status":"issued"}'];
        yield 'supplied+agent' => [new HandleResolution('ses_A', SessionIdSource::Supplied, 'a|b|c'), '{"session_id":"ses_A","agent_id":"a|b|c","status":"active"}'];
        yield 'invalid+agent' => [new HandleResolution('', SessionIdSource::Invalid, 'a|b|c'), '{"agent_id":"a|b|c","status":"unrecognized"}'];
        yield 'invalid' => [new HandleResolution('', SessionIdSource::Invalid), '{"status":"unrecognized"}'];
        yield 'foreign' => [new HandleResolution('', SessionIdSource::Foreign), null];
        yield 'foreign+agent' => [new HandleResolution('', SessionIdSource::Foreign, 'a|b|c'), '{"agent_id":"a|b|c"}'];
        yield 'hook+agent' => [new HandleResolution('', SessionIdSource::Hook, 'a|b|c', true), '{"agent_id":"a|b|c"}'];
        yield 'hook' => [new HandleResolution('', SessionIdSource::Hook, null, true), null];
        yield 'empty agent id is falsy' => [new HandleResolution('ses_A', SessionIdSource::Supplied, ''), '{"session_id":"ses_A","status":"active"}'];
        yield 'hook mode hides a minted session' => [new HandleResolution('ses_A', SessionIdSource::Minted, null, true), null];
    }

    #[Group('ac-04-39')]
    #[DataProvider('payloadStates')]
    public function testStructuredPayloadPerState(HandleResolution $resolution, ?string $expected): void
    {
        $payload = MintBack::structuredPayload($resolution);

        $this->assertSame($expected, $payload instanceof \stdClass ? $this->enc($payload) : null);
    }

    #[Group('ac-04-39')]
    public function testMirrorPutsMcpSessionFirst(): void
    {
        $result = $this->obj('{"structuredContent":{"count":42,"0":"z"},"isError":false}');
        $before = $this->enc($result);

        $out = MintBack::mirror($result, $this->obj('{"status":"issued"}'));

        $this->assertSame('{"structuredContent":{"mcp_session":{"status":"issued"},"count":42,"0":"z"},"isError":false}', $this->enc($out));
        $this->assertSame($before, $this->enc($result));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function resultsNotMirrored(): iterable
    {
        yield 'absent' => ['{"content":[]}'];
        yield 'list' => ['{"structuredContent":[1]}'];
        yield 'scalar' => ['{"structuredContent":5}'];
        yield 'null' => ['{"structuredContent":null}'];
        yield 'already holds mcp_session' => ['{"structuredContent":{"mcp_session":{"mine":1}}}'];
    }

    #[Group('ac-04-40')]
    #[DataProvider('resultsNotMirrored')]
    public function testMirrorLeavesOtherShapesAlone(string $json): void
    {
        $result = $this->obj($json);

        $this->assertSame($result, MintBack::mirror($result, $this->obj('{"status":"issued"}')));
    }

    #[Group('ac-04-40')]
    public function testMirrorIgnoresNonObjectResults(): void
    {
        $this->assertNull(MintBack::mirror(null, $this->obj('{"status":"issued"}')));
    }

    #[Group('ac-04-42')]
    public function testDecorateSkipsInputRequiredRounds(): void
    {
        $result = $this->obj('{"resultType":"input_required","content":[],"structuredContent":{"a":1}}');

        $this->assertSame($result, MintBack::decorate($result, new HandleResolution('ses_A', SessionIdSource::Minted), true));
    }

    #[Group('ac-04-35')]
    #[Group('ac-04-39')]
    public function testDecorateAppliesTextAndMirror(): void
    {
        $result = $this->obj('{"content":[],"structuredContent":{"count":42}}');

        $out = MintBack::decorate($result, new HandleResolution('ses_A', SessionIdSource::Minted), true);

        $text = (string) MintBack::text(new HandleResolution('ses_A', SessionIdSource::Minted));
        $expected = $this->enc((object) [
            'content' => [(object) ['type' => 'text', 'text' => $text]],
            'structuredContent' => (object) ['mcp_session' => (object) ['session_id' => 'ses_A', 'status' => 'issued'], 'count' => 42],
        ]);
        $this->assertSame($expected, $this->enc($out));
    }

    #[Group('ac-04-41')]
    public function testDecorateWithoutMirrorAddsTextOnly(): void
    {
        $result = $this->obj('{"content":[],"structuredContent":{"count":42}}');

        $out = MintBack::decorate($result, new HandleResolution('ses_A', SessionIdSource::Minted), false);

        $text = (string) MintBack::text(new HandleResolution('ses_A', SessionIdSource::Minted));
        $expected = $this->enc((object) [
            'content' => [(object) ['type' => 'text', 'text' => $text]],
            'structuredContent' => (object) ['count' => 42],
        ]);
        $this->assertSame($expected, $this->enc($out));
    }

    #[Group('ac-04-38')]
    public function testDecorateMirrorsAgentIdOnlyWhenTextIsAbsent(): void
    {
        $result = $this->obj('{"content":[],"structuredContent":{}}');

        $out = MintBack::decorate($result, new HandleResolution('', SessionIdSource::Hook, 'a', true), true);

        $this->assertSame('{"content":[],"structuredContent":{"mcp_session":{"agent_id":"a"}}}', $this->enc($out));
    }

    public function testDecorateLeavesResultWhenNothingToSay(): void
    {
        $result = $this->obj('{"content":[],"structuredContent":{}}');

        $this->assertSame($result, MintBack::decorate($result, new HandleResolution('', SessionIdSource::Foreign), true));
        $this->assertSame('x', MintBack::decorate('x', new HandleResolution('ses_A', SessionIdSource::Minted), true));
    }
}
