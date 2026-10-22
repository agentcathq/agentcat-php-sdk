<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E\Scenarios;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Tools\GetMoreTools;
use AgentCat\Tests\Support\Golden;
use AgentCat\Tests\Support\JsonAssert;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The 20 end-to-end scenarios that the TypeScript SDK runs on every lane of its real-HTTP matrix, with its calls and
 * its checks: a real mcp/sdk server, tracked with `Scenario::options()`, the tools of `ScenarioToolkit`, a client over
 * real sockets, and the mock collector. A lane class starts its server and adds the scenarios of its own topology:
 * `rebuild-on-demand` (per-request lanes) or `session-continuity-stateful` (the long-lived lane).
 *
 * The sink shows what each tool received after AgentCat's strip; the event shows the raw call.
 *
 * @phpstan-require-extends TestCase
 */
trait Scenarios
{
    /** The agent ID that the agent-tracking scenarios supply. */
    private const string AGENT_ID = 'opus-4.80-1m|claude-code|k3n9x';

    /** The canned get_more_tools answer contains this. */
    private const string FULL_TOOL_LIST = 'we have shown you the full tool list';

    /** The line of AgentCat's log for a call that rebuilt the registries of its server instance. */
    private const string REBUILT = 'Rebuilt injection registries on demand (tools/call before tools/list on this instance)';

    /** Starts the scenario's server on this lane when the lane needs one, and returns a connected client. */
    abstract protected function open(Scenario $scenario): ScenarioClient;

    /** Whether the lane speaks the 2026-07-28 era: no handshake, the envelope on every request. */
    abstract protected function modern(): bool;

    /**
     * Waits until this test's collector scenario holds `$count` requests, and returns the requests it holds then.
     *
     * @return list<\stdClass>
     */
    abstract protected function deliveries(int $count): array;

    /** Proves that no event of this test reached the collector: the lane's barrier, then an empty collector scenario. */
    abstract protected function assertNothingDelivered(ScenarioClient $client): void;

    /** The sink of this test's tool calls. */
    abstract protected function sink(): Sink;

    /** 1. The listing injects context and session_id; a call without session_id mints; one event; the sink is stripped. */
    #[Group('ac-02-53')]
    #[Group('ac-02-6')]
    #[Group('ac-02-30')]
    #[MaximumDuration(10000)]
    public function testBaselineDefaults(): void
    {
        $client = $this->open(Scenario::BaselineDefaults);

        $tools = $client->listTools();
        $this->assertContains('echo', $this->names($tools));
        $this->assertContains(GetMoreTools::NAME, $this->names($tools));
        $echo = $this->tool($tools, 'echo');
        $this->assertSame(Constants::DEFAULT_CONTEXT_PARAMETER_DESCRIPTION, JsonAssert::at($this->properties($echo), 'context', 'description'));
        $this->assertContains(Constants::PARAM_SESSION_ID, $this->required($echo));
        $this->assertContains(Constants::PARAM_CONTEXT, $this->required($echo));
        $this->assertSame(Constants::SESSION_ID_PARAM_PATTERN, JsonAssert::at($this->properties($echo), 'session_id', 'pattern'));

        // Requiredness is for schema-aware clients: a call without session_id still mints and announces it.
        $result = $client->call('echo', ['msg' => 'hi', 'context' => 'e2e smoke intent']);

        $sessionId = $this->issuedSessionId($result);
        $event = $this->event();
        $this->assertSame('echo', JsonAssert::at($event, 'resource_name'));
        $this->assertSame($sessionId, JsonAssert::at($event, 'session_id'));
        $this->assertSame('e2e smoke intent', JsonAssert::at($event, 'user_intent'));
        $this->assertSame('minted', $this->tag($event, Constants::TAG_SESSION_SOURCE));
        $this->assertSame('{"msg":"hi","context":"e2e smoke intent"}', $this->json($this->rawArguments($event)), 'the event keeps the raw call');
        $this->assertSame(['{"msg":"hi"}'], $this->sink()->seen('echo'), 'the tool saw neither session_id nor context');
        $this->assertSame($this->modern() ? '2026-07-28' : null, $this->tag($event, Constants::TAG_PROTOCOL_VERSION), 'the envelope tags its era');
    }

    /** 2. Tracing off: no handles are listed, but context is; the call is plain; no event. */
    #[Group('ac-02-53')]
    #[Group('ac-02-52')]
    #[MaximumDuration(10000)]
    public function testTracingOff(): void
    {
        $client = $this->open(Scenario::TracingOff);

        $properties = $this->properties($this->tool($client->listTools(), 'echo'));
        $this->assertObjectNotHasProperty(Constants::PARAM_SESSION_ID, $properties);
        $this->assertObjectNotHasProperty(Constants::PARAM_AGENT_ID, $properties);
        $this->assertObjectHasProperty(Constants::PARAM_CONTEXT, $properties, 'enableToolCallContext governs context, as in TypeScript');

        $result = $client->call('echo', ['msg' => 'hi']);

        $this->assertSame(['hi'], $this->texts($result));
        $this->assertNull($this->mintBack($result));
        $this->assertNothingDelivered($client);
        $this->assertSame(['{"msg":"hi"}'], $this->sink()->seen('echo'));
    }

    /** 3. Tracing off, report-missing on: get_more_tools is listed and answered, with no block and no event. */
    #[Group('ac-02-53')]
    #[Group('ac-02-42')]
    #[MaximumDuration(10000)]
    public function testTracingOffWithReportMissingOn(): void
    {
        $client = $this->open(Scenario::TracingOffReportMissingOn);
        $this->assertContains(GetMoreTools::NAME, $this->names($client->listTools()));

        $result = $client->call(GetMoreTools::NAME, ['context' => 'need a search tool']);

        $this->assertStringContainsString(self::FULL_TOOL_LIST, $this->text($result, 0));
        $this->assertNull($this->mintBack($result));
        $this->assertNothingDelivered($client);
    }

    /** 4. Report-missing off: no get_more_tools; injection, minting and the event still work. */
    #[Group('ac-02-53')]
    #[Group('ac-01-16')]
    #[MaximumDuration(10000)]
    public function testReportMissingOff(): void
    {
        $client = $this->open(Scenario::ReportMissingOff);

        $tools = $client->listTools();
        $this->assertNotContains(GetMoreTools::NAME, $this->names($tools));
        $properties = $this->properties($this->tool($tools, 'echo'));
        $this->assertObjectHasProperty(Constants::PARAM_SESSION_ID, $properties);
        $this->assertObjectHasProperty(Constants::PARAM_CONTEXT, $properties);

        $result = $client->call('echo', ['msg' => 'hi', 'context' => 'no gmt']);

        $sessionId = $this->issuedSessionId($result);
        $this->assertSame($sessionId, JsonAssert::at($this->event(), 'session_id'));
    }

    /** 5. A get_more_tools call: the canned text after the block, and one event with the context as intent. */
    #[Group('ac-02-53')]
    #[Group('ac-02-40')]
    #[MaximumDuration(10000)]
    public function testReportMissingCall(): void
    {
        $client = $this->open(Scenario::ReportMissingCall);
        $client->listTools();
        $missing = 'Need a database query tool for SQL operations';

        $result = $client->call(GetMoreTools::NAME, ['context' => $missing]);

        $this->assertStringContainsString('Unfortunately', $this->text($result, 1));
        $this->assertStringContainsString(self::FULL_TOOL_LIST, $this->text($result, 1));
        $sessionId = $this->issuedSessionId($result);
        $event = $this->event();
        $this->assertSame(GetMoreTools::NAME, JsonAssert::at($event, 'resource_name'));
        $this->assertSame($missing, JsonAssert::at($event, 'user_intent'));
        $this->assertSame($sessionId, JsonAssert::at($event, 'session_id'));
        $this->assertSame($missing, JsonAssert::at($this->rawArguments($event), 'context'), 'get_more_tools\' own context stays on the event');
        $this->assertStringNotContainsString('[session_id', $this->json($event->response ?? null), 'the block is wire-only');
    }

    /** 6. Context off: no context parameter; a supplied session is honored; no intent. */
    #[Group('ac-02-53')]
    #[Group('ac-01-17')]
    #[MaximumDuration(10000)]
    public function testContextOff(): void
    {
        $client = $this->open(Scenario::ContextOff);

        $properties = $this->properties($this->tool($client->listTools(), 'echo'));
        $this->assertObjectNotHasProperty(Constants::PARAM_CONTEXT, $properties);
        $this->assertObjectHasProperty(Constants::PARAM_SESSION_ID, $properties);

        $client->call('echo', ['msg' => 'hi', 'session_id' => $this->sid('ctxoff')]);

        $event = $this->event();
        $this->assertSame($this->sid('ctxoff'), JsonAssert::at($event, 'session_id'));
        $this->assertSame('supplied', $this->tag($event, Constants::TAG_SESSION_SOURCE));
        $this->assertNull($event->user_intent ?? null);
    }

    /** 7. A custom context description on the customer's tools; get_more_tools keeps its own. */
    #[Group('ac-02-53')]
    #[Group('ac-01-18')]
    #[MaximumDuration(10000)]
    public function testContextCustomDescription(): void
    {
        $client = $this->open(Scenario::ContextCustomDescription);

        $tools = $client->listTools();
        $this->assertSame(Scenario::CUSTOM_CONTEXT_DESCRIPTION, JsonAssert::at($this->properties($this->tool($tools, 'echo')), 'context', 'description'));
        $this->assertSame(
            JsonAssert::at(Golden::json('handles/strings.json'), 'GET_MORE_TOOLS_DESCRIPTOR', 'inputSchema', 'properties', 'context', 'description'),
            JsonAssert::at($this->properties($this->tool($tools, GetMoreTools::NAME)), 'context', 'description'),
            'get_more_tools keeps its own context description',
        );

        $client->call('echo', ['msg' => 'hi', 'context' => 'custom description intent']);

        $event = $this->event();
        $this->assertSame('echo', JsonAssert::at($event, 'resource_name'));
        $this->assertSame('custom description intent', JsonAssert::at($event, 'user_intent'));
    }

    /** 8. Agent tracking: both handles required everywhere; start mints a session but never an agent. */
    #[Group('ac-02-53')]
    #[Group('ac-01-23')]
    #[Group('ac-02-7')]
    #[MaximumDuration(10000)]
    public function testAgentTrackingMint(): void
    {
        $client = $this->open(Scenario::AgentTrackingMint);

        $tools = $client->listTools();
        foreach (['echo', 'structured', GetMoreTools::NAME] as $name) {
            $tool = $this->tool($tools, $name);
            $this->assertObjectHasProperty(Constants::PARAM_AGENT_ID, $this->properties($tool), $name);
            $this->assertContains(Constants::PARAM_AGENT_ID, $this->required($tool), $name);
            $this->assertContains(Constants::PARAM_SESSION_ID, $this->required($tool), $name);
        }

        $result = $client->call('structured', ['msg' => 'hi', 'context' => 'agent minting', 'session_id' => 'start']);

        $block = $this->mintBack($result);
        $this->assertIsString($block);
        $this->assertStringNotContainsString(Constants::PARAM_AGENT_ID, $block);
        $sessionId = $this->issuedSessionId($result);
        $this->assertSame('{"session_id":"' . $sessionId . '","status":"issued"}', $this->json(JsonAssert::at($result, 'structuredContent', Constants::MCP_SESSION_KEY)), 'no agent_id in the mirror');
        $this->assertSame('hi', JsonAssert::at($result, 'structuredContent', 'echoed'));
        $event = $this->event();
        $this->assertSame($sessionId, JsonAssert::at($event, 'session_id'));
        $this->assertSame('minted', $this->tag($event, Constants::TAG_SESSION_SOURCE));
        $this->assertNull($this->tag($event, Constants::TAG_AGENT_ID));
        $this->assertNull($this->tag($event, Constants::TAG_AGENT_SOURCE));
    }

    /** 9. Agent tracking: a supplied agent_id on the second call is tagged verbatim; the first call has no agent tag. */
    #[Group('ac-02-53')]
    #[Group('ac-01-22')]
    #[MaximumDuration(10000)]
    public function testAgentTrackingSupplied(): void
    {
        $client = $this->open(Scenario::AgentTrackingSupplied);
        $client->listTools();
        $first = $client->call('echo', ['msg' => 'one', 'context' => 'agent supplied 1', 'session_id' => 'start']);
        $block = $this->mintBack($first);
        $this->assertIsString($block);
        $this->assertStringNotContainsString(Constants::PARAM_AGENT_ID, $block);
        $sessionId = $this->issuedSessionId($first);

        $second = $client->call('echo', ['msg' => 'two', 'context' => 'agent supplied 2', 'session_id' => $sessionId, 'agent_id' => self::AGENT_ID]);

        $this->assertNull($this->mintBack($second), 'nothing minted, no block');
        [$one, $two] = $this->twoEvents();
        $this->assertNull($this->tag($one, Constants::TAG_AGENT_ID));
        $this->assertSame($sessionId, JsonAssert::at($two, 'session_id'));
        $this->assertSame('supplied', $this->tag($two, Constants::TAG_SESSION_SOURCE));
        $this->assertSame(self::AGENT_ID, $this->tag($two, Constants::TAG_AGENT_ID));
        $this->assertSame('supplied', $this->tag($two, Constants::TAG_AGENT_SOURCE));
    }

    /** 10. Agent tracking in hook mode: no session_id parameter, agent_id required; no block; one derived session. */
    #[Group('ac-02-53')]
    #[Group('ac-04-17')]
    #[MaximumDuration(10000)]
    public function testAgentTrackingWithASessionHook(): void
    {
        $client = $this->open(Scenario::AgentTrackingHook);
        $tools = $client->listTools();
        foreach (['echo', GetMoreTools::NAME] as $name) {
            $tool = $this->tool($tools, $name);
            $this->assertObjectNotHasProperty(Constants::PARAM_SESSION_ID, $this->properties($tool), $name);
            $this->assertObjectHasProperty(Constants::PARAM_AGENT_ID, $this->properties($tool), $name);
            $this->assertContains(Constants::PARAM_AGENT_ID, $this->required($tool), $name);
        }

        $first = $client->call('echo', ['msg' => 'one', 'context' => 'hook plus agent', 'agent_id' => self::AGENT_ID]);
        $second = $client->call('echo', ['msg' => 'two', 'context' => 'hook plus agent', 'agent_id' => self::AGENT_ID]);

        $this->assertNull($this->mintBack($first), 'hook mode never adds a block');
        $this->assertNull($this->mintBack($second));
        $events = $this->twoEvents();
        $this->assertMatchesRegularExpression('/\Ases_[0-9A-Za-z]{27}\z/', $this->string(JsonAssert::at($events[0], 'session_id')));
        $this->assertSame(JsonAssert::at($events[0], 'session_id'), JsonAssert::at($events[1], 'session_id'), 'one hook value, one session');
        foreach ($events as $event) {
            $this->assertSame('hook', $this->tag($event, Constants::TAG_SESSION_SOURCE));
            $this->assertSame(self::AGENT_ID, $this->tag($event, Constants::TAG_AGENT_ID));
            $this->assertSame('supplied', $this->tag($event, Constants::TAG_AGENT_SOURCE));
        }
    }

    /** 11. A deterministic session hook: two calls (two server instances on a per-request lane) share one session. */
    #[Group('ac-02-53')]
    #[Group('ac-01-27')]
    #[MaximumDuration(10000)]
    public function testTaskHookDeterministic(): void
    {
        $client = $this->open(Scenario::TaskHookDeterministic);
        $client->listTools();

        $first = $client->call('echo', ['msg' => 'one', 'context' => 'hook determinism']);
        $second = $client->call('echo', ['msg' => 'two', 'context' => 'hook determinism']);

        $this->assertNull($this->mintBack($first));
        $this->assertNull($this->mintBack($second));
        $events = $this->twoEvents();
        $this->assertMatchesRegularExpression('/\Ases_[0-9A-Za-z]{27}\z/', $this->string(JsonAssert::at($events[0], 'session_id')));
        $this->assertSame(JsonAssert::at($events[0], 'session_id'), JsonAssert::at($events[1], 'session_id'));
        foreach ($events as $event) {
            $this->assertSame('hook', $this->tag($event, Constants::TAG_SESSION_SOURCE));
        }
    }

    /** 12. A session hook that returns null: each call mints its own session silently. */
    #[Group('ac-02-53')]
    #[Group('ac-01-29')]
    #[MaximumDuration(10000)]
    public function testTaskHookNull(): void
    {
        $client = $this->open(Scenario::TaskHookNull);
        $client->listTools();

        $first = $client->call('echo', ['msg' => 'one', 'context' => 'hook null']);
        $second = $client->call('echo', ['msg' => 'two', 'context' => 'hook null']);

        $this->assertNull($this->mintBack($first), 'the agent can never learn a silently minted session');
        $this->assertNull($this->mintBack($second));
        $events = $this->twoEvents();
        foreach ($events as $event) {
            $this->assertMatchesRegularExpression('/\Ases_[0-9A-Za-z]{27}\z/', $this->string(JsonAssert::at($event, 'session_id')));
            $this->assertSame('minted', $this->tag($event, Constants::TAG_SESSION_SOURCE));
        }
        $this->assertNotSame(JsonAssert::at($events[0], 'session_id'), JsonAssert::at($events[1], 'session_id'));
    }

    /** 13. start mints a session; the agent sends it back; both events carry it, minted then supplied. */
    #[Group('ac-02-53')]
    #[Group('ac-02-32')]
    #[MaximumDuration(10000)]
    public function testTaskSuppliedContinuity(): void
    {
        $client = $this->open(Scenario::TaskSuppliedContinuity);
        $client->listTools();
        $sessionId = $this->issuedSessionId($client->call('echo', ['msg' => 'one', 'context' => 'continuity 1', 'session_id' => 'start']));

        $second = $client->call('echo', ['msg' => 'two', 'context' => 'continuity 2', 'session_id' => $sessionId]);

        $this->assertNull($this->mintBack($second));
        [$one, $two] = $this->twoEvents();
        $this->assertSame($sessionId, JsonAssert::at($one, 'session_id'));
        $this->assertSame($sessionId, JsonAssert::at($two, 'session_id'));
        $this->assertSame('minted', $this->tag($one, Constants::TAG_SESSION_SOURCE));
        $this->assertSame('supplied', $this->tag($two, Constants::TAG_SESSION_SOURCE));
    }

    /**
     * 14. The structured mirror: mcp_session first in structuredContent, matching the block; the result passes the
     * output schema the client listed (the JSON Schema validator of mcp/sdk stands in for the client's); wire-only.
     */
    #[Group('ac-02-53')]
    #[Group('ac-02-36')]
    #[MaximumDuration(10000)]
    public function testMintBackStructuredMirror(): void
    {
        $client = $this->open(Scenario::MintBackStructuredMirror);
        $outputSchema = JsonAssert::at($this->tool($client->listTools(), 'structured'), 'outputSchema');
        $this->assertObjectHasProperty(Constants::MCP_SESSION_KEY, $this->object(JsonAssert::at($outputSchema, 'properties')));

        $result = $client->call('structured', ['msg' => 'mirrored', 'context' => 'structured mirror', 'session_id' => 'start']);

        $sessionId = $this->issuedSessionId($result);
        $structured = JsonAssert::at($result, 'structuredContent');
        $this->assertInstanceOf(\stdClass::class, $structured);
        $this->assertSame([Constants::MCP_SESSION_KEY, 'echoed'], array_keys(get_object_vars($structured)), 'the mirror leads structuredContent');
        $this->assertSame('{"session_id":"' . $sessionId . '","status":"issued"}', $this->json($structured->mcp_session ?? null));
        $this->assertSame('mirrored', $structured->echoed ?? null);
        $this->assertInstanceOf(\stdClass::class, $outputSchema);
        $this->assertSame([], (new SchemaValidator())->validateAgainstJsonSchema($structured, $outputSchema), 'the result passes the listed output schema');
        $event = $this->event();
        $this->assertSame($sessionId, JsonAssert::at($event, 'session_id'));
        $this->assertStringNotContainsString(Constants::MCP_SESSION_KEY, $this->json($event->response ?? null), 'the mirror is wire-only');
        $this->assertStringNotContainsString('[session_id', $this->json($event->response ?? null), 'the block is wire-only');
    }

    /** 15. A strict schema: additionalProperties is dropped from the listing, and the handles are stripped before validation. */
    #[Group('ac-02-53')]
    #[Group('ac-02-17')]
    #[MaximumDuration(10000)]
    public function testStrictSchemaInjection(): void
    {
        $client = $this->open(Scenario::StrictSchemaInjection);
        $strict = $this->tool($client->listTools(), 'strict');
        $this->assertObjectNotHasProperty('additionalProperties', $this->object(JsonAssert::at($strict, 'inputSchema')));
        $this->assertObjectHasProperty(Constants::PARAM_SESSION_ID, $this->properties($strict));
        $this->assertObjectHasProperty(Constants::PARAM_CONTEXT, $this->properties($strict));

        $result = $client->call('strict', ['msg' => 'strict ok', 'context' => 'strict schema test', 'session_id' => $this->sid('strict1')]);

        $this->assertFalse(JsonAssert::at($result, 'isError'));
        $this->assertSame(['strict ok'], $this->texts($result));
        $this->assertSame($this->sid('strict1'), JsonAssert::at($this->event(), 'session_id'));
        // The strip proof is the successful call: mcp/sdk validates it against the original strict schema.
        $this->assertSame(['{"msg":"strict ok"}'], $this->sink()->seen('strict'));
    }

    /** 16. The strip proof on a loose schema: the customer's extra key reaches the tool, the handles do not; the event keeps all. */
    #[Group('ac-02-53')]
    #[Group('ac-02-18')]
    #[MaximumDuration(10000)]
    public function testStripProofLoose(): void
    {
        $client = $this->open(Scenario::StripProofLoose);
        $client->listTools();

        $client->call('loose', ['msg' => 'keep', 'customer_extra' => 'customer says hi', 'context' => 'strip proof', 'session_id' => $this->sid('raw1')]);

        $this->assertSame(['{"msg":"keep","customer_extra":"customer says hi"}'], $this->sink()->seen('loose'));
        $event = $this->event();
        $this->assertSame(
            '{"msg":"keep","customer_extra":"customer says hi","context":"strip proof","session_id":"' . $this->sid('raw1') . '"}',
            $this->json($this->rawArguments($event)),
        );
        $this->assertSame($this->sid('raw1'), JsonAssert::at($event, 'session_id'));
        $this->assertSame('strip proof', JsonAssert::at($event, 'user_intent'));
    }

    /** 17. The error path: an isError result with the block, an isError event, and the server keeps serving. */
    #[Group('ac-02-53')]
    #[Group('ac-02-24')]
    #[Group('ac-02-29')]
    #[Group('ac-02-33')]
    #[MaximumDuration(10000)]
    public function testErrorPath(): void
    {
        $client = $this->open(Scenario::ErrorPath);
        $client->listTools();

        $result = $client->call('boom', ['context' => 'error path intent']);

        $this->assertTrue(JsonAssert::at($result, 'isError'));
        $sessionId = $this->issuedSessionId($result); // an isError result still announces the session
        $error = $this->event();
        $this->assertSame('boom', JsonAssert::at($error, 'resource_name'));
        $this->assertTrue(JsonAssert::at($error, 'is_error'));
        $this->assertStringContainsString('boom', $this->string(JsonAssert::at($error, 'error', 'message')));
        $this->assertSame('error path intent', JsonAssert::at($error, 'user_intent'));
        $this->assertSame($sessionId, JsonAssert::at($error, 'session_id'));
        $this->assertSame('minted', $this->tag($error, Constants::TAG_SESSION_SOURCE));
        $this->assertStringNotContainsString('[session_id', $this->json($error->response ?? null), 'the block is wire-only');

        $followUp = $client->call('echo', ['msg' => 'still alive', 'context' => 'post-error']);

        $this->assertFalse(JsonAssert::at($followUp, 'isError'));
        $events = $this->twoEvents();
        $this->assertSame('echo', JsonAssert::at($events[1], 'resource_name'));
        $this->assertObjectNotHasProperty('is_error', $events[1]);
    }

    /** 18. identify, eventTags (with a collision on an SDK tag, which the SDK wins) and eventProperties on the event. */
    #[Group('ac-02-53')]
    #[Group('ac-02-49')]
    #[Group('ac-01-41')]
    #[MaximumDuration(10000)]
    public function testHooksIdentityTagsAndProperties(): void
    {
        $client = $this->open(Scenario::HooksIdentityTagsProps);
        $client->listTools();

        $client->call('echo', ['msg' => 'hi', 'context' => 'hooks combo']);

        $event = $this->event();
        $this->assertSame(Scenario::IDENTITY_USER_ID, JsonAssert::at($event, 'identify_actor_given_id'));
        $this->assertSame(Scenario::IDENTITY_USER_NAME, JsonAssert::at($event, 'identify_actor_name'));
        $this->assertSame($this->json(Scenario::IDENTITY_USER_DATA), $this->json(JsonAssert::at($event, 'identify_data')));
        $this->assertSame(Scenario::CUSTOMER_TAG_VALUE, $this->tag($event, Scenario::CUSTOMER_TAG_KEY));
        $this->assertSame('minted', $this->tag($event, Constants::TAG_SESSION_SOURCE), 'the SDK wins the collision');
        $this->assertSame(Scenario::CUSTOMER_PROP_VALUE, JsonAssert::at($event, 'properties', Scenario::CUSTOMER_PROP_KEY));
    }

    /** 19. String redaction: the secret is replaced in the response, the arguments and the intent; protected fields stay. */
    #[Group('ac-02-53')]
    #[Group('ac-01-48')]
    #[MaximumDuration(10000)]
    public function testRedaction(): void
    {
        $client = $this->open(Scenario::Redaction);
        $client->listTools();

        $client->call('echo', [
            'msg' => 'payload with ' . Scenario::REDACTION_SECRET . ' inside',
            'context' => 'intent mentioning ' . Scenario::REDACTION_SECRET,
            'session_id' => $this->sid('redact'),
        ]);

        $event = $this->event();
        $response = $this->json($event->response ?? null);
        $this->assertStringContainsString(Scenario::REDACTION_REPLACEMENT, $response);
        $this->assertStringNotContainsString(Scenario::REDACTION_SECRET, $response);
        $this->assertSame('payload with ' . Scenario::REDACTION_REPLACEMENT . ' inside', JsonAssert::at($this->rawArguments($event), 'msg'));
        $this->assertSame('intent mentioning ' . Scenario::REDACTION_REPLACEMENT, JsonAssert::at($event, 'user_intent'));
        $this->assertSame($this->sid('redact'), JsonAssert::at($event, 'session_id'));
        $this->assertSame('echo', JsonAssert::at($event, 'resource_name'));
    }

    /**
     * 20. The client identity ladder, per lane. In the handshake era the identity comes from `initialize`: on the
     * long-lived lane from the instance that served it; on the per-request lane from the session store, which every
     * fresh instance reads (TypeScript's per-request instances keep no session, and find no identity). The handshake
     * era has no protocol tag, but a `_meta` claim on a call gives one; mcp/sdk routes a call that claims 2026-07-28 to
     * the other era, so the claim here is the negotiated revision. In the 2026-07-28 era, the envelope of each request
     * carries the identity and the tag.
     */
    #[Group('ac-02-53')]
    #[Group('ac-02-44')]
    #[Group('ac-02-45')]
    #[MaximumDuration(10000)]
    public function testClientIdentityLadder(): void
    {
        $client = $this->open(Scenario::ClientIdentityLadder);
        $client->listTools();

        $client->call('echo', ['msg' => 'who am I', 'context' => 'identity ladder']);

        $event = $this->event();
        $this->assertSame(ScenarioClient::CLIENT_INFO['name'], JsonAssert::at($event, 'client_name'));
        $this->assertSame(ScenarioClient::CLIENT_INFO['version'], JsonAssert::at($event, 'client_version'));
        if ($this->modern()) {
            $this->assertSame('2026-07-28', $this->tag($event, Constants::TAG_PROTOCOL_VERSION));

            return;
        }
        $this->assertNull($this->tag($event, Constants::TAG_PROTOCOL_VERSION), 'a negotiated version is no claim');

        $client->call('echo', ['msg' => 'again', 'context' => 'identity ladder meta'], [Constants::META_PROTOCOL_VERSION_KEY => '2025-06-18']);

        $events = $this->twoEvents();
        $this->assertSame('2025-06-18', $this->tag($events[1], Constants::TAG_PROTOCOL_VERSION));
    }

    /** Waits for the one event of this test. */
    private function event(): \stdClass
    {
        return $this->events(1)[0];
    }

    /**
     * Waits for the two events of this test, in call order.
     *
     * @return array{\stdClass, \stdClass}
     */
    private function twoEvents(): array
    {
        $events = $this->events(2);
        $second = $events[1] ?? null;
        $this->assertInstanceOf(\stdClass::class, $second);

        return [$events[0], $second];
    }

    /**
     * Waits for exactly `$count` events of this test, in call order: by `timestamp`, the start of each call. A
     * per-request lane delivers each event at the end of its own request, so two requests can deliver out of order.
     *
     * @return non-empty-list<\stdClass>
     */
    private function events(int $count): array
    {
        $events = array_map(static function (\stdClass $request): \stdClass {
            self::assertSame('POST', $request->method ?? null);
            $body = $request->body ?? null;
            self::assertIsString($body);
            $event = Json::decode($body);
            self::assertInstanceOf(\stdClass::class, $event, $body);

            return $event;
        }, $this->deliveries($count));
        $this->assertCount($count, $events, 'events of this test');
        usort($events, fn(\stdClass $a, \stdClass $b): int => strcmp($this->string($a->timestamp ?? null), $this->string($b->timestamp ?? null)));
        $this->assertNotSame([], $events);

        return $events;
    }

    /**
     * The `ses_` ID of the issued block at the start of a result; fails unless the block is there.
     */
    private function issuedSessionId(\stdClass $result): string
    {
        $block = $this->mintBack($result);
        $this->assertIsString($block, 'the issued block');
        $this->assertStringStartsWith(Constants::MINT_BACK_HEADER_ISSUED . "\n", $block);
        $this->assertSame(1, preg_match('/^session_id: (ses_[0-9A-Za-z]{27})$/m', $block, $match), $block);

        return $match[1];
    }

    /** The text of the session block at the start of a result, or null. */
    private function mintBack(\stdClass $result): ?string
    {
        $first = $this->texts($result)[0] ?? null;

        return is_string($first) && str_starts_with($first, '[session_id') ? $first : null;
    }

    /** @return list<mixed> the `text` of every content block */
    private function texts(\stdClass $result): array
    {
        $content = $result->content ?? null;
        $this->assertIsList($content);

        return array_map(static fn(mixed $block): mixed => JsonAssert::at($block, 'text'), $content);
    }

    private function text(\stdClass $result, int $index): string
    {
        return $this->string($this->texts($result)[$index] ?? null);
    }

    /**
     * @param list<\stdClass> $tools
     *
     * @return list<mixed>
     */
    private function names(array $tools): array
    {
        return array_map(static fn(\stdClass $tool): mixed => $tool->name ?? null, $tools);
    }

    /** @param list<\stdClass> $tools */
    private function tool(array $tools, string $name): \stdClass
    {
        foreach ($tools as $tool) {
            if (($tool->name ?? null) === $name) {
                return $tool;
            }
        }

        self::fail('No tool ' . $name . ' in ' . $this->json($this->names($tools)));
    }

    private function properties(\stdClass $tool): \stdClass
    {
        $properties = JsonAssert::at($tool, 'inputSchema', 'properties');
        $this->assertInstanceOf(\stdClass::class, $properties);

        return $properties;
    }

    /** @return list<mixed> */
    private function required(\stdClass $tool): array
    {
        $required = JsonAssert::at($tool, 'inputSchema', 'required');
        $this->assertIsList($required);

        return $required;
    }

    /** The raw arguments of the call, as the event records them. */
    private function rawArguments(\stdClass $event): mixed
    {
        return JsonAssert::at($event, 'parameters', 'request', 'params', 'arguments');
    }

    /** An event tag, or null when the event has no such tag. */
    private function tag(\stdClass $event, string $name): mixed
    {
        $tags = $event->tags ?? null;
        $this->assertInstanceOf(\stdClass::class, $tags);

        return $tags->{$name} ?? null;
    }

    /** A valid `ses_` session ID made from `$label`, as TypeScript's `sid()` makes it. */
    private function sid(string $label): string
    {
        return 'ses_' . substr(preg_replace('/[^0-9A-Za-z]/', '', $label) . str_repeat('0', 27), 0, 27);
    }

    private function object(mixed $value): \stdClass
    {
        $this->assertInstanceOf(\stdClass::class, $value);

        return $value;
    }

    private function string(mixed $value): string
    {
        $this->assertIsString($value);

        return $value;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
