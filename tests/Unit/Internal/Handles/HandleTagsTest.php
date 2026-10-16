<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Handles;

use AgentCat\Internal\Handles\HandleResolution;
use AgentCat\Internal\Handles\HandleTags;
use AgentCat\Internal\Handles\SessionIdSource;
use AgentCat\Internal\Support\Js;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class HandleTagsTest extends TestCase
{
    private function agentTag(string $agentId): string
    {
        return HandleTags::build(new HandleResolution('', SessionIdSource::Minted, $agentId), null)['agentcat_agent_id'] ?? '<absent>';
    }

    #[Group('ac-04-32')]
    public function testLineBreaksBecomeOneSpaceEach(): void
    {
        $this->assertSame('line1 line2  line3', $this->agentTag("line1\nline2\r\nline3"));
    }

    #[Group('ac-04-32')]
    public function testAgentIdIsClampedTo200Units(): void
    {
        $this->assertSame(str_repeat('a', 200), $this->agentTag(str_repeat('a', 500)));
        $this->assertSame(str_repeat('a', 200), $this->agentTag(str_repeat('a', 200)));
    }

    #[Group('ac-04-32')]
    public function testEmojiClampCountsUtf16Units(): void
    {
        $out = $this->agentTag(str_repeat("\u{1F600}", 150));
        $this->assertSame(str_repeat("\u{1F600}", 100), $out);
        $this->assertSame(200, Js::utf16Length($out));
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
    }

    #[Group('ac-04-32')]
    public function testClampNeverLeavesHalfSurrogate(): void
    {
        $out = $this->agentTag(str_repeat('a', 199) . "\u{1F600}");
        $this->assertSame(str_repeat('a', 199), $out);
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
    }

    #[Group('ac-04-33')]
    public function testSourceTagForEverySource(): void
    {
        foreach (SessionIdSource::cases() as $source) {
            $tags = HandleTags::build(new HandleResolution('', $source), null);
            $this->assertSame([ 'agentcat_session_id_source' => $source->value], $tags);
        }
    }

    #[Group('ac-04-33')]
    public function testAgentAndProtocolTags(): void
    {
        $tags = HandleTags::build(new HandleResolution('', SessionIdSource::Supplied, 'opus'), '2025-06-18');
        $this->assertSame([
            'agentcat_session_id_source' => 'supplied',
            'agentcat_agent_id' => 'opus',
            'agentcat_agent_id_source' => 'supplied',
            'agentcat_protocol_version' => '2025-06-18',
        ], $tags);
    }

    #[Group('ac-04-33')]
    public function testEmptyProtocolVersionOmitted(): void
    {
        $r = new HandleResolution('', SessionIdSource::Minted);
        $this->assertArrayNotHasKey('agentcat_protocol_version', HandleTags::build($r, ''));
        $this->assertArrayNotHasKey('agentcat_protocol_version', HandleTags::build($r, null));
        $this->assertArrayNotHasKey('agentcat_agent_id', HandleTags::build($r, null));
    }

    #[Group('ac-04-33')]
    public function testEmptyAgentIdOmitted(): void
    {
        $this->assertSame('<absent>', $this->agentTag(''));
    }

    #[Group('ac-04-33')]
    public function testMergeSdkTagsWin(): void
    {
        $merged = HandleTags::merge(
            ['agentcat_session_id_source' => 'customer', 'env' => 'x'],
            ['agentcat_session_id_source' => 'minted'],
        );
        $this->assertSame(['agentcat_session_id_source' => 'minted', 'env' => 'x'], $merged);
    }

    #[Group('ac-04-33')]
    public function testMergeAcceptsNullAndIntKeys(): void
    {
        $this->assertSame(['a' => 'b'], HandleTags::merge(null, ['a' => 'b']));
        $this->assertSame([123 => 'v', 'a' => 'b'], HandleTags::merge([123 => 'v'], ['a' => 'b']));
    }

    #[Group('ac-04-34')]
    public function testUserIntent(): void
    {
        $this->assertSame('why', HandleTags::userIntent((object) ['context' => 'why'], 'echo', true));
        $this->assertNull(HandleTags::userIntent((object) ['x' => 1], 'echo', true));
        $this->assertSame('why', HandleTags::userIntent((object) ['context' => 'why'], 'get_more_tools', false));
        $this->assertNull(HandleTags::userIntent((object) ['context' => 'why'], 'echo', false));
        $this->assertSame(5, HandleTags::userIntent((object) ['context' => 5], 'echo', true));
        $this->assertSame('  raw ', HandleTags::userIntent((object) ['context' => '  raw '], 'echo', true));
    }

    #[Group('ac-04-34')]
    public function testUserIntentNonObjectArguments(): void
    {
        $this->assertNull(HandleTags::userIntent(null, 'echo', true));
        $this->assertNull(HandleTags::userIntent(['context' => 'x'], 'get_more_tools', true));
    }
}
