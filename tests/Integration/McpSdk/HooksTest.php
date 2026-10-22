<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Event;
use AgentCat\Identity;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Id\SessionId;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Options;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use AgentCat\Tests\Support\McpSdk\Tools;
use AgentCat\Tests\Support\McpSdk\TrackingFixture;
use AgentCat\ToolCall;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Customer hooks through a real server: what they see, and that they run after the response (D04). */
final class HooksTest extends TestCase
{
    private const string SESSION = 'ses_bbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private TrackingFixture $fixture;

    #[\Override]
    protected function setUp(): void
    {
        $this->fixture = new TrackingFixture();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    #[Group('ac-01-31')]
    #[Group('ac-01-32')]
    #[Group('ac-01-33')]
    #[Group('ac-02-49')]
    public function testIdentifySeesTheRawCallOncePerCall(): void
    {
        /** @var list<ToolCall> $seen */
        $seen = [];
        $harness = $this->tracked(new Options(identify: static function (ToolCall $call) use (&$seen): Identity {
            $seen[] = $call;

            return new Identity('user-' . (is_string($call->arguments['msg'] ?? null) ? $call->arguments['msg'] : ''));
        }));

        foreach (['1', '2', '3'] as $msg) {
            $harness->callTool('echo', ['msg' => $msg, 'context' => 'why', 'session_id' => 'start']);
        }

        $events = $this->fixture->events();
        $this->assertCount(3, $seen);
        $this->assertSame(['msg' => '1', 'context' => 'why', 'session_id' => 'start'], $seen[0]->arguments, 'unstripped');
        $this->assertSame('echo', $seen[0]->toolName);
        $this->assertInstanceOf(CallToolRequest::class, $seen[0]->request);
        $this->assertSame($seen[0]->arguments, $seen[0]->request->arguments);
        $this->assertInstanceOf(SessionInterface::class, $seen[0]->context);
        $this->assertSame(['user-1', 'user-2', 'user-3'], array_map(static fn(Event $event): ?string => $event->identifyActorGivenId, $events));
    }

    #[Group('ac-01-37')]
    #[Group('ac-02-48')]
    public function testHooksRunAfterTheResponse(): void
    {
        $runs = new \ArrayObject();
        $harness = $this->tracked(new Options(identify: static function () use ($runs): null {
            $runs->append(true);

            return null;
        }));

        $answer = $harness->callTool('echo', ['msg' => 'hi']);

        $this->assertObjectHasProperty('result', $answer);
        $this->assertCount(0, $runs, 'the client has its answer before any hook runs');
        $this->fixture->events();
        $this->assertCount(1, $runs);
    }

    #[Group('ac-02-50')]
    #[Group('ac-01-29')]
    public function testAThrowingIdentifyLeavesTheCallAndTheEventAnonymous(): void
    {
        $harness = $this->tracked(new Options(identify: static function (): never {
            throw new \RuntimeException('who?');
        }));

        $answer = $harness->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $this->assertSame('echo: hi', JsonAssert::at(HttpHarness::result($answer), 'content', 1, 'text'));
        $event = $this->fixture->event();
        $this->assertNull($event->identifyActorGivenId);
        $this->assertSame('{}', $this->json($event->identifyActorData));
        $this->assertContains('Error: identify callback threw - RuntimeException: who?', $this->fixture->lines());
    }

    /**
     * An `Identity` always has a userId, so an identity without one reaches AgentCat as an array or another object.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function identitiesWithoutAUserId(): iterable
    {
        yield 'an array' => [['userName' => 'Ada', 'userData' => ['plan' => 'pro']]];
        yield 'a plain object' => [(object) ['userName' => 'Ada', 'userData' => ['plan' => 'pro']]];
    }

    #[DataProvider('identitiesWithoutAUserId')]
    #[Group('ac-01-36')]
    public function testAnIdentityWithoutAUserIdLeavesTheEventAnonymous(mixed $identity): void
    {
        $harness = $this->tracked(Options::fromArray(['identify' => static fn(): mixed => $identity]));

        $answer = $harness->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);

        $this->assertSame('echo: hi', JsonAssert::at(HttpHarness::result($answer), 'content', 0, 'text'));
        $this->fixture->event();
        $this->assertCount(1, $this->fixture->transport->deliveries);
        $body = Json::decode($this->fixture->transport->deliveries[0]->body);
        $this->assertInstanceOf(\stdClass::class, $body);
        foreach (['actor_id', 'identify_actor_given_id', 'identify_actor_name'] as $key) {
            $this->assertObjectNotHasProperty($key, $body);
        }
        $this->assertSame('{}', $this->json(JsonAssert::at($body, 'identify_data')));
        $this->assertContains(sprintf('WARN: identify returned %s, expected AgentCat\Identity or null; the event stays anonymous', get_debug_type($identity)), $this->fixture->lines());
    }

    /**
     * What AgentCat keeps for a tracked server is its project ID, its options and its listing; the rest of its state
     * is read-only. The calls it serves leave nothing there, an identity or a session no more than anything else: each
     * event has its own call's identity.
     */
    #[Group('ac-01-39')]
    public function testTheStateOfAServerKeepsNothingOfItsCalls(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $options = new Options(identify: static fn(ToolCall $call): Identity => new Identity('user-' . (is_string($call->arguments['msg'] ?? null) ? $call->arguments['msg'] : '')));
        $state = $this->fixture->track($builder, $options);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();
        $listed = [$state->registry, $state->outputRegistry, $state->everyPageRead];
        $injection = clone $state->injection;

        $harness->callTool('echo', ['msg' => '1', 'session_id' => 'start']);
        $harness->callTool('echo', ['msg' => '2', 'session_id' => self::SESSION]);

        $events = $this->fixture->events();
        $this->assertSame(['user-1', 'user-2'], array_map(static fn(Event $event): ?string => $event->identifyActorGivenId, $events));
        $this->assertSame([TrackingFixture::PROJECT, $options], [$state->projectId, $state->options]);
        $this->assertSame($listed, [$state->registry, $state->outputRegistry, $state->everyPageRead], 'the calls changed nothing in the listing');
        $this->assertEquals($injection, $state->injection, 'nor in the injection state');
    }

    #[Group('ac-01-30')]
    public function testAThrowingResolveSessionIdMintsASessionAndAnnouncesNothing(): void
    {
        $harness = $this->tracked(new Options(resolveSessionId: static function (): never {
            throw new \RuntimeException('no session');
        }));

        $answer = $harness->callTool('echo', ['msg' => 'hi']);

        $this->assertEquals([(object) ['type' => 'text', 'text' => 'echo: hi']], JsonAssert::at(HttpHarness::result($answer), 'content'));
        $event = $this->fixture->event();
        $this->assertMatchesRegularExpression(SessionId::PATTERN, $event->sessionId);
        $this->assertSame('minted', $event->tags[Constants::TAG_SESSION_SOURCE] ?? null);
        $this->assertContains('resolveSessionId hook error: RuntimeException: no session', $this->fixture->lines());
    }

    #[Group('ac-01-27')]
    #[Group('ac-01-28')]
    public function testTheHookSessionIsDerivedTheSameWayOnEveryServer(): void
    {
        $resolve = static fn(ToolCall $call): ?string => is_string($call->arguments['msg'] ?? null) ? $call->arguments['msg'] : null;
        foreach (['customer-correlation-7', 'customer-correlation-7', 'customer-correlation-8'] as $value) {
            $this->tracked(new Options(resolveSessionId: $resolve))->callTool('echo', ['msg' => $value]);
        }

        $events = $this->fixture->events();
        $this->assertCount(3, $events);
        $expected = SessionId::derive('customer-correlation-7', TrackingFixture::PROJECT);
        $this->assertSame([$expected, $expected], [$events[0]->sessionId, $events[1]->sessionId]);
        $this->assertSame(SessionId::derive('customer-correlation-8', TrackingFixture::PROJECT), $events[2]->sessionId);
        $this->assertNotSame($expected, $events[2]->sessionId);
        $this->assertSame('hook', $events[0]->tags[Constants::TAG_SESSION_SOURCE] ?? null);
    }

    #[Group('ac-05-R20')]
    public function testRedactEventSeesTheHookResultsBeforeTheEventHasAnId(): void
    {
        /** @var list<mixed> $seen */
        $seen = [];
        $harness = $this->tracked(new Options(
            identify: static fn(): Identity => new Identity('u-redact'),
            redactEvent: static function (Event $event) use (&$seen): Event {
                $seen = [$event->identifyActorGivenId, $event->tags['env'] ?? null, $event->id];

                return $event;
            },
            eventTags: static fn(): array => ['env' => 'prod'],
        ));

        $harness->callTool('echo', ['msg' => 'hi']);

        $event = $this->fixture->event();
        $this->assertSame(['u-redact', 'prod', ''], $seen);
        $this->assertNotSame('', $event->id);
    }

    #[Group('ac-05-R21')]
    public function testTheHooksSeeTheRawTextAndTheDeliveredBodyTheRedactedOne(): void
    {
        $raw = null;
        $harness = $this->tracked(new Options(
            identify: static function (ToolCall $call) use (&$raw): null {
                $raw = $call->arguments['text'] ?? null;

                return null;
            },
            redactSensitiveInformation: static fn(string $text): string => str_replace('secret', '[gone]', $text),
        ));

        $harness->callTool('echo', ['msg' => 'hi', 'text' => 'this contains a secret value']);

        $this->fixture->event();
        $this->assertSame('this contains a secret value', $raw);
        $this->assertCount(1, $this->fixture->transport->deliveries);
        $body = $this->fixture->transport->deliveries[0]->body;
        $this->assertStringContainsString('this contains a [gone] value', $body);
        $this->assertStringNotContainsString('secret', $body);
    }

    private function tracked(Options $options): HttpHarness
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->fixture->track($builder, $options);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        return $harness;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
