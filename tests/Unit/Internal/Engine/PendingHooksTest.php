<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Engine;

use AgentCat\Event;
use AgentCat\Identity;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\PendingHooks;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Id\SessionId;
use AgentCat\Internal\Logging\Log;
use AgentCat\Options;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class PendingHooksTest extends TestCase
{
    private MemoryLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    public function testNoHooksGiveNoClosures(): void
    {
        $this->assertSame([], PendingHooks::build($this->server(new Options()), $this->call()));
    }

    #[Group('ac-03-26')]
    #[Group('ac-01-27')]
    public function testResolveSessionIdDerivesTheSessionId(): void
    {
        $event = $this->apply(new Options(resolveSessionId: static fn(ToolCall $c): string => '  x  '), 'proj_1');

        $this->assertSame(SessionId::derive('x', 'proj_1'), $event->sessionId);
        $this->assertSame(['agentcat_session_id_source' => 'hook'], $event->tags);
    }

    #[Group('ac-03-26')]
    public function testTelemetryOnlyProjectDerivesWithoutProject(): void
    {
        $event = $this->apply(new Options(resolveSessionId: static fn(ToolCall $c): string => 'x'), '');

        $this->assertSame(SessionId::derive('x', null), $event->sessionId);
    }

    /** The hook result is the first tag key and stays the first. */
    public function testSessionSourceKeepsItsPosition(): void
    {
        $event = $this->apply(new Options(resolveSessionId: static fn(ToolCall $c): string => 'x'), 'proj_1', ['agentcat_session_id_source' => 'minted', 'other' => 'o']);

        $this->assertSame(['agentcat_session_id_source' => 'hook', 'other' => 'o'], $event->tags);
    }

    /** @return iterable<string, array{mixed}> */
    public static function unusableSessionIds(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'int' => [42];
    }

    #[Group('ac-01-29')]
    #[DataProvider('unusableSessionIds')]
    public function testUnusableSessionIdIsMinted(mixed $value): void
    {
        $event = $this->apply(Options::fromArray(['resolveSessionId' => static fn(ToolCall $c): mixed => $value]), 'proj_1');

        $this->assertMatchesRegularExpression(SessionId::PATTERN, $event->sessionId);
        $this->assertSame(['agentcat_session_id_source' => 'minted'], $event->tags);
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-01-29')]
    public function testThrowingResolveSessionIdIsMintedAndLogged(): void
    {
        $event = $this->apply(new Options(resolveSessionId: static fn(ToolCall $c): never => throw new \RuntimeException('no')), 'proj_1');

        $this->assertMatchesRegularExpression(SessionId::PATTERN, $event->sessionId);
        $this->assertSame(['agentcat_session_id_source' => 'minted'], $event->tags);
        $this->assertSame(['resolveSessionId hook error: RuntimeException: no'], $this->logger->messages());
    }

    #[Group('ac-03-20')]
    #[Group('ac-01-31')]
    public function testIdentifyFillsTheIdentity(): void
    {
        $full = $this->apply(new Options(identify: static fn(ToolCall $c): Identity => new Identity('u1', 'N', ['a' => 1])));
        $bare = $this->apply(new Options(identify: static fn(ToolCall $c): Identity => new Identity('u2')));

        $this->assertSame(['u1', 'N', ['a' => 1]], [$full->identifyActorGivenId, $full->identifyActorName, $full->identifyActorData]);
        $this->assertSame('u2', $bare->identifyActorGivenId);
        $this->assertNull($bare->identifyActorName);
        $this->assertEquals(new \stdClass(), $bare->identifyActorData);
    }

    #[Group('ac-01-34')]
    #[Group('ac-01-35')]
    #[Group('ac-05-E18')]
    public function testIdentifyNullOrThrowLeavesTheEventAnonymous(): void
    {
        $none = $this->apply(new Options(identify: static fn(ToolCall $c): ?Identity => null));
        $this->assertNull($none->identifyActorGivenId);
        $this->assertSame([], $this->logger->messages());

        $threw = $this->apply(new Options(identify: static fn(ToolCall $c): never => throw new \RuntimeException('down')));
        $this->assertNull($threw->identifyActorGivenId);
        $this->assertSame(['Error: identify callback threw - RuntimeException: down'], $this->logger->messages());
    }

    /** An `identify` hook that returns an array, not an `Identity`: one WARN line, and the event stays anonymous. */
    public function testIdentifyArrayWarnsAndStaysAnonymous(): void
    {
        $event = $this->apply(Options::fromArray(['identify' => static fn(ToolCall $c): array => ['userId' => 'x']]));

        $this->assertNull($event->identifyActorGivenId);
        $this->assertSame(['WARN: identify returned array, expected AgentCat\Identity or null; the event stays anonymous'], $this->logger->messages());
    }

    /** An `eventTags` hook that returns a string, not an array, keeps only the SDK tags, silently. */
    public function testEventTagsStringKeepsTheSdkTags(): void
    {
        $event = $this->apply(Options::fromArray(['eventTags' => static fn(ToolCall $c): string => 'abc']), 'proj_1', ['agentcat_session_id_source' => 'hook']);

        $this->assertSame(['agentcat_session_id_source' => 'hook'], $event->tags);
        $this->assertSame([], $this->logger->messages());
    }

    /** @return iterable<string, array{mixed}> */
    public static function falsyTags(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'empty string' => [''];
        yield 'empty array' => [[]];
    }

    /** ac-01-45: a falsy eventTags result keeps only the SDK tags, silently. */
    #[DataProvider('falsyTags')]
    #[Group('ac-01-45')]
    public function testFalsyEventTagsKeepTheSdkTags(mixed $tags): void
    {
        $event = $this->apply(Options::fromArray(['eventTags' => static fn(ToolCall $c): mixed => $tags]), 'proj_1', ['agentcat_session_id_source' => 'hook']);

        $this->assertSame(['agentcat_session_id_source' => 'hook'], $event->tags);
        $this->assertSame([], $this->logger->messages());
    }

    /** An `eventProperties` hook that returns false leaves the event without properties, silently. */
    public function testEventPropertiesFalseLeavesNoProperties(): void
    {
        $event = $this->apply(new Options(eventProperties: static fn(ToolCall $c): bool => false));

        $this->assertNull($event->properties);
        $this->assertSame([], $this->logger->messages());
    }

    /** A hook whose own return type declaration fails throws a TypeError at hook time. */
    public function testReturnTypeErrorInsideAHookIsLoggedOnce(): void
    {
        // @phpstan-ignore return.type (the test needs a hook that breaks its own declared return type)
        $event = $this->apply(new Options(identify: static fn(ToolCall $c): Identity => null));

        $this->assertNull($event->identifyActorGivenId);
        $this->assertCount(1, $this->logger->messages());
        $this->assertStringStartsWith('Error: identify callback threw - TypeError: ', $this->logger->messages()[0]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function hookLabels(): iterable
    {
        yield 'resolveSessionId' => ['resolveSessionId', 'resolveSessionId hook error: '];
        yield 'identify' => ['identify', 'Error: identify callback threw - '];
        yield 'eventTags' => ['eventTags', 'eventTags callback error: '];
        yield 'eventProperties' => ['eventProperties', 'eventProperties callback error: '];
    }

    /**
     * D32: a hook built by Options::fromArray() with the wrong arity or parameter type throws at hook time.
     * invoke() logs the hook's own line, the guard has nothing to catch, nothing reaches the caller.
     */
    #[DataProvider('hookLabels')]
    public function testHooksFromArrayWithAWrongSignatureAreLoggedAndSwallowed(string $key, string $prefix): void
    {
        $tooMany = $this->apply(Options::fromArray([$key => static fn(ToolCall $a, ToolCall $b): mixed => null]));
        $wrongType = $this->apply(Options::fromArray([$key => static fn(int $a): mixed => null]));

        $this->assertNull($tooMany->properties);
        $this->assertNull($wrongType->properties);
        $messages = $this->logger->messages();
        $this->assertCount(2, $messages);
        $this->assertStringStartsWith($prefix . 'ArgumentCountError: ', $messages[0]);
        $this->assertStringStartsWith($prefix . 'TypeError: ', $messages[1]);
    }

    #[Group('ac-01-40')]
    #[Group('ac-01-41')]
    #[Group('ac-03-18')]
    public function testSdkTagsWinAndCustomerTagsComeFirst(): void
    {
        $event = $this->apply(
            new Options(eventTags: static fn(ToolCall $c): array => ['env' => 'test', 'agentcat_session_id_source' => 'customer']),
            'proj_1',
            ['agentcat_session_id_source' => 'hook'],
        );

        $this->assertSame(['env' => 'test', 'agentcat_session_id_source' => 'hook'], $event->tags);
        $this->assertSame(['env', 'agentcat_session_id_source'], array_keys($event->tags ?? []));
    }

    #[Group('ac-01-42')]
    public function testInvalidTagsAreDropped(): void
    {
        $event = $this->apply(
            new Options(eventTags: static fn(ToolCall $c): array => ['valid' => 'v', 'invalid!' => 'v']),
            'proj_1',
            ['agentcat_session_id_source' => 'hook'],
        );

        $this->assertSame(['valid' => 'v', 'agentcat_session_id_source' => 'hook'], $event->tags);
        $this->assertSame(['Dropping invalid tag: "invalid!" — key contains invalid characters or is empty'], $this->logger->messages());
    }

    /** eventTags results go through TagValidator: non-string values and keys never reach the wire unvalidated. */
    public function testNonStringTagKeysAndValuesAreValidated(): void
    {
        $event = $this->apply(
            new Options(eventTags: static fn(ToolCall $c): array => ['n' => 7, 'list' => ['a'], 'obj' => new \stdClass(), 'ok' => 's', 'b' => true, 'nl' => "a\nb", 5 => 'five']),
            'proj_1',
            ['agentcat_session_id_source' => 'hook'],
        );

        $this->assertSame([5 => 'five', 'ok' => 's', 'agentcat_session_id_source' => 'hook'], $event->tags);
        $this->assertCount(5, $this->logger->messages());
    }

    public function testNothingValidLeavesTheSdkTags(): void
    {
        $event = $this->apply(new Options(eventTags: static fn(ToolCall $c): array => ['n' => 7]), 'proj_1', ['agentcat_session_id_source' => 'hook']);

        $this->assertSame(['agentcat_session_id_source' => 'hook'], $event->tags);
    }

    public function testEventTagsObjectIsValidatedToo(): void
    {
        $tags = new \stdClass();
        $tags->a = 'b';
        $event = $this->apply(Options::fromArray(['eventTags' => static fn(ToolCall $c): object => $tags]), 'proj_1', []);

        $this->assertSame(['a' => 'b'], $event->tags);
    }

    #[Group('ac-01-45')]
    public function testThrowingTagAndPropertyHooksAreLogged(): void
    {
        $tags = $this->apply(
            new Options(eventTags: static fn(ToolCall $c): never => throw new \RuntimeException('x')),
            'proj_1',
            ['agentcat_session_id_source' => 'hook'],
        );
        $properties = $this->apply(new Options(eventProperties: static fn(ToolCall $c): never => throw new \RuntimeException('y')));

        $this->assertSame(['agentcat_session_id_source' => 'hook'], $tags->tags);
        $this->assertNull($properties->properties);
        $this->assertSame(['eventTags callback error: RuntimeException: x', 'eventProperties callback error: RuntimeException: y'], $this->logger->messages());
    }

    #[Group('ac-01-46')]
    #[Group('ac-03-19')]
    public function testEventPropertiesAreSetAndEmptyBecomesAnObject(): void
    {
        $set = $this->apply(new Options(eventProperties: static fn(ToolCall $c): array => ['device' => 'mobile']));
        $empty = $this->apply(new Options(eventProperties: static fn(ToolCall $c): array => []));

        $this->assertSame(['device' => 'mobile'], $set->properties);
        $this->assertEquals(new \stdClass(), $empty->properties);
    }

    public function testHooksRunInTypeScriptOrder(): void
    {
        $order = [];
        $options = new Options(
            resolveSessionId: static function (ToolCall $c) use (&$order): ?string {
                $order[] = 'resolveSessionId';

                return null;
            },
            identify: static function (ToolCall $c) use (&$order): ?Identity {
                $order[] = 'identify';

                return null;
            },
            eventTags: static function (ToolCall $c) use (&$order): array {
                $order[] = 'eventTags';

                return [];
            },
            eventProperties: static function (ToolCall $c) use (&$order): array {
                $order[] = 'eventProperties';

                return [];
            },
        );

        $this->apply($options);

        $this->assertSame(['resolveSessionId', 'identify', 'eventTags', 'eventProperties'], $order);
    }

    public function testGuardSwallowsAndLogsAThrow(): void
    {
        $guarded = PendingHooks::guarded('identify', static fn(Event $e): never => throw new \LogicException('x'));
        $guarded(new Event());

        $this->assertSame(['Warning: identify hook failed in background - LogicException: x'], $this->logger->messages());
    }

    public function testGuardIsSilentWhenTheStepReturns(): void
    {
        $ran = false;
        PendingHooks::guarded('identify', static function (Event $e) use (&$ran): void {
            $ran = true;
        })(new Event());

        $this->assertTrue($ran);
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-05-R20')]
    public function testTheEventKeepsItsIdForThePipeline(): void
    {
        $event = $this->apply(
            new Options(identify: static fn(ToolCall $c): Identity => new Identity('u1'), eventTags: static fn(ToolCall $c): array => ['env' => 'x']),
            'proj_1',
            ['agentcat_session_id_source' => 'hook'],
        );

        $this->assertSame('u1', $event->identifyActorGivenId);
        $this->assertSame(['env' => 'x', 'agentcat_session_id_source' => 'hook'], $event->tags);
        $this->assertSame('', $event->id);
    }

    /** @param array<array-key, string> $tags */
    private function apply(Options $options, string $projectId = 'proj_1', array $tags = ['agentcat_session_id_source' => 'hook']): Event
    {
        $event = new Event(tags: $tags);
        foreach (PendingHooks::build($this->server($options, $projectId), $this->call()) as $apply) {
            $apply($event);
        }

        return $event;
    }

    private function server(Options $options, string $projectId = 'proj_1'): TrackedServer
    {
        return new TrackedServer($projectId, $options, static fn(): array => [null, null], new Publisher(new EventQueue(transport: new RecordingTransport())));
    }

    private function call(): ToolCall
    {
        return new ToolCall(toolName: 'echo', arguments: ['msg' => 'hi']);
    }
}
