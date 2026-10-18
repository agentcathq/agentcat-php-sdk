<?php

declare(strict_types=1);

namespace AgentCat\Tests\Golden;

use AgentCat\Internal\Constants;
use AgentCat\Tests\Support\Golden;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ConstantsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtureConstants(): iterable
    {
        foreach ([
            'DEFAULT_CONTEXT_PARAMETER_DESCRIPTION',
            'SESSION_ID_PARAM_DESCRIPTION',
            'SESSION_ID_PARAM_PATTERN',
            'AGENT_ID_PARAM_DESCRIPTION',
            'MINT_BACK_HEADER_ISSUED',
            'MINT_BACK_ISSUED_BODY',
            'MINT_BACK_HEADER_UNRECOGNIZED',
            'MINT_BACK_UNRECOGNIZED_BODY',
            'MCP_SESSION_FIELD_DESCRIPTION',
            'MCP_SESSION_FIELD_DESCRIPTION_HOOK_MODE',
            'MCP_SESSION_SESSION_ID_DESCRIPTION',
            'MCP_SESSION_AGENT_ID_DESCRIPTION',
            'MCP_SESSION_STATUS_DESCRIPTION',
        ] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('fixtureConstants')]
    #[Group('ac-04-1')]
    #[Group('ac-04-2')]
    #[Group('ac-04-3')]
    #[Group('ac-04-4')]
    #[Group('ac-01-88')]
    public function testConstantIsByteIdenticalToFixture(string $name): void
    {
        $fixture = Golden::json('handles/strings.json');
        $this->assertInstanceOf(\stdClass::class, $fixture);
        $this->assertSame(Constants::{$name}, $fixture->{$name});
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function digests(): iterable
    {
        yield 'DEFAULT_CONTEXT_PARAMETER_DESCRIPTION' => ['DEFAULT_CONTEXT_PARAMETER_DESCRIPTION', 'e7dc0082fe80a9da46414d690b6b9ba95e7afa768a4390cf67251acc22e588ec'];
        yield 'SESSION_ID_PARAM_DESCRIPTION' => ['SESSION_ID_PARAM_DESCRIPTION', '3142169f96247b2c3662d8eb800ede0f9e48e12effd4744033f605907d97a4bc'];
        yield 'SESSION_ID_PARAM_PATTERN' => ['SESSION_ID_PARAM_PATTERN', 'a00e8ea775570f2b7d9fcce277c64117f83efd38458f6accfbe45fd3372c3f5e'];
        yield 'AGENT_ID_PARAM_DESCRIPTION' => ['AGENT_ID_PARAM_DESCRIPTION', '4e39487f330b8f50601348738f2886ab7e9f7d3ec05c2d93850b63ed4a0758e2'];
        yield 'MINT_BACK_HEADER_ISSUED' => ['MINT_BACK_HEADER_ISSUED', '970b64824cb4a5a62f1548804756cd584991ba4f5707a146fb4d77947f8c2298'];
        yield 'MINT_BACK_ISSUED_BODY' => ['MINT_BACK_ISSUED_BODY', '849bc1a2df49ea0fa6638abe4d322853824f2d53496bd7681864fa862e1fc22a'];
        yield 'MINT_BACK_HEADER_UNRECOGNIZED' => ['MINT_BACK_HEADER_UNRECOGNIZED', '1585e173e0e647fae125a3269054d3716e26763fd191872ff1a7739cd54e8f20'];
        yield 'MINT_BACK_UNRECOGNIZED_BODY' => ['MINT_BACK_UNRECOGNIZED_BODY', '1a47f19f6e04b58fc83376a9eaaea0cd6cf9e1686752447e7d3b705b48279b20'];
        yield 'MCP_SESSION_FIELD_DESCRIPTION' => ['MCP_SESSION_FIELD_DESCRIPTION', '6af85db3af588aca25446b579bb06f023531ddcf6b37a9d71c4726a386cea2d7'];
        yield 'MCP_SESSION_FIELD_DESCRIPTION_HOOK_MODE' => ['MCP_SESSION_FIELD_DESCRIPTION_HOOK_MODE', 'ce0ff00a089aa4a2f7489377edfa9a00b95b821e229961a3c21e7155bf990362'];
        yield 'MCP_SESSION_SESSION_ID_DESCRIPTION' => ['MCP_SESSION_SESSION_ID_DESCRIPTION', 'ae03955a71926a9d21854ddf76340d25da73df4263b81b9d9ca9c0548d3b4359'];
        yield 'MCP_SESSION_AGENT_ID_DESCRIPTION' => ['MCP_SESSION_AGENT_ID_DESCRIPTION', '83129a136b6c5f357abb5ec230d18724e8fbff62dbff0ba78dc4da0c06537c57'];
        yield 'MCP_SESSION_STATUS_DESCRIPTION' => ['MCP_SESSION_STATUS_DESCRIPTION', '73303adaab23c8e1857f4f31d450b959e52ad959efe7f27a461be99c87252025'];
    }

    #[DataProvider('digests')]
    #[Group('ac-04-1')]
    #[Group('ac-04-2')]
    #[Group('ac-04-3')]
    #[Group('ac-04-4')]
    public function testConstantMatchesAuditSha256(string $name, string $digest): void
    {
        $value = Constants::{$name};
        $this->assertIsString($value);
        $this->assertSame($digest, hash('sha256', $value));
    }

    #[Group('ac-04-2')]
    public function testSessionIdPatternAcceptsOnlyValidHandles(): void
    {
        $regex = '/' . Constants::SESSION_ID_PARAM_PATTERN . '/D';
        $this->assertSame(1, preg_match($regex, 'start'));
        $this->assertSame(1, preg_match($regex, 'ses_' . str_repeat('a', 27)));
        $this->assertSame(0, preg_match($regex, 'ses_' . str_repeat('a', 25)));
        $this->assertSame(0, preg_match($regex, 'restart'));
        $this->assertSame(0, preg_match($regex, 'START'));
    }

    #[Group('ac-04-2')]
    public function testSessionIdDescriptionNamesBothMintBackChannels(): void
    {
        $text = Constants::SESSION_ID_PARAM_DESCRIPTION;
        $this->assertStringContainsString('mcp_session', $text);
        $this->assertStringContainsString('a text block at the start of the result beginning [session_id issued', $text);
        $this->assertStringContainsString('a text block beginning [session_id unrecognized', $text);
    }

    #[Group('ac-04-3')]
    public function testAgentIdDescriptionContent(): void
    {
        $text = Constants::AGENT_ID_PARAM_DESCRIPTION;
        $this->assertStringContainsString('required on every call including your first', $text);
        $this->assertStringContainsString('opus-4.80-1m|claude-code|k3n9x', $text);
        $this->assertStringContainsString('never inherited', $text);
        $this->assertStringContainsString('generates a new one', $text);
        $this->assertStringNotContainsString('the server will issue', $text);
        $this->assertStringNotContainsString('session_id', $text);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shortConstants(): iterable
    {
        yield 'SESSION_START_SENTINEL' => ['start', Constants::SESSION_START_SENTINEL];
        yield 'MCP_SESSION_KEY' => ['mcp_session', Constants::MCP_SESSION_KEY];
        yield 'META_CLIENT_INFO_KEY' => ['io.modelcontextprotocol/clientInfo', Constants::META_CLIENT_INFO_KEY];
        yield 'META_PROTOCOL_VERSION_KEY' => ['io.modelcontextprotocol/protocolVersion', Constants::META_PROTOCOL_VERSION_KEY];
        yield 'TAG_AGENT_ID' => ['agentcat_agent_id', Constants::TAG_AGENT_ID];
        yield 'TAG_SESSION_SOURCE' => ['agentcat_session_id_source', Constants::TAG_SESSION_SOURCE];
        yield 'TAG_AGENT_SOURCE' => ['agentcat_agent_id_source', Constants::TAG_AGENT_SOURCE];
        yield 'TAG_PROTOCOL_VERSION' => ['agentcat_protocol_version', Constants::TAG_PROTOCOL_VERSION];
        yield 'TAG_MRTR' => ['agentcat_mrtr', Constants::TAG_MRTR];
        yield 'PARAM_SESSION_ID' => ['session_id', Constants::PARAM_SESSION_ID];
        yield 'PARAM_AGENT_ID' => ['agent_id', Constants::PARAM_AGENT_ID];
        yield 'PARAM_CONTEXT' => ['context', Constants::PARAM_CONTEXT];
        yield 'CUSTOM_EVENT_TYPE' => ['agentcat:custom', Constants::CUSTOM_EVENT_TYPE];
        yield 'TOOLS_CALL_EVENT_TYPE' => ['mcp:tools/call', Constants::TOOLS_CALL_EVENT_TYPE];
        yield 'SOURCE' => ['agentcat', Constants::SOURCE];
        yield 'DIAGNOSTICS_SCOPE_NAME' => ['agentcat-diagnostics', Constants::DIAGNOSTICS_SCOPE_NAME];
        yield 'DEFAULT_API_BASE_URL' => ['https://api.agentcat.com', Constants::DEFAULT_API_BASE_URL];
        yield 'DEFAULT_DIAGNOSTICS_ENDPOINT' => ['https://otel.agentcat.com', Constants::DEFAULT_DIAGNOSTICS_ENDPOINT];
        yield 'DEFAULT_DIAGNOSTICS_TOKEN' => ['dgk_sdk_diag_3f9a2c7e1b8d4065af2e9c1d7b6a4f80', Constants::DEFAULT_DIAGNOSTICS_TOKEN];
    }

    #[DataProvider('shortConstants')]
    #[Group('ac-04-1')]
    public function testShortConstant(string $expected, string $actual): void
    {
        $this->assertSame($expected, $actual);
    }
}
