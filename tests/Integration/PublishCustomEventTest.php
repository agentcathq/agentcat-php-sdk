<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration;

use AgentCat\Client;
use AgentCat\CustomEvent;
use AgentCat\Event;
use AgentCat\Internal\ClientSeams;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\SdkVersion;
use AgentCat\Options;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\TrackingFixture;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingExporter;
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\Tests\Support\Thrown;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Protocol;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/** `Client::publishCustomEvent()` in its server and session forms, on real mcp/sdk Builders and servers. */
final class PublishCustomEventTest extends TestCase
{
    private const string PROJECT = 'proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string NO_PROJECT = 'projectId is required for publishCustomEvent';

    private const string NOT_TRACKED = 'Server is not tracked. Please call agentcat.track() first or provide a session ID string.';

    private const string NO_SESSION = 'publishCustomEvent: no sessionId provided; event will be published without a session. Pass eventData.sessionId to attribute it to a task.';

    private const string PUBLISHED_WITHOUT_SESSION = "Published custom event without a session with type 'agentcat:custom'";

    private const string COMPATIBILITY = 'AgentCat SDK Compatibility: AgentCat supports mcp/sdk 0.8.1+ (pass the Mcp\Server\Builder to AgentCat::track() before build()).';

    private Environment $environment;

    private MemoryLogger $logger;

    private RecordingTransport $transport;

    private RecordingExporter $exporter;

    private EventQueue $queue;

    private Client $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->environment = Environment::clear('AGENTCAT_PROJECT_ID', 'AGENTCAT_API_URL', 'MCPCAT_API_URL', 'AGENTCAT_ENABLED', 'APP_ENV');
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        $this->transport = new RecordingTransport();
        $this->exporter = new RecordingExporter();
        $this->queue = new EventQueue(transport: $this->transport, exporters: $this->exporter);
        $this->client = new Client(ClientSeams::of($this->queue, static fn(): bool => true));
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
        $this->environment->restore();
    }

    /** @return iterable<string, array{?string}> */
    public static function noProject(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
    }

    #[DataProvider('noProject')]
    #[Group('ac-01-66')]
    public function testAProjectIdIsRequiredAndCheckedFirst(?string $project): void
    {
        $untracked = TrackingFixture::builder();

        foreach ([$untracked, 'ses_1'] as $first) {
            $error = Thrown::by(\InvalidArgumentException::class, fn() => $this->client->publishCustomEvent($first, $project, new CustomEvent()));
            $this->assertSame(self::NO_PROJECT, $error->getMessage());
        }
        $this->assertNothingQueued();
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-01-67')]
    public function testAFirstArgumentThatIsNeitherObjectNorStringIsATypeError(): void
    {
        $bad = 42;

        $this->expectException(\TypeError::class);

        $this->client->publishCustomEvent($bad, 'p'); // @phpstan-ignore argument.type (proves PHP's own type check)
    }

    /** @return iterable<string, array{\Closure(): object}> */
    public static function untracked(): iterable
    {
        yield 'a Builder' => [TrackingFixture::builder(...)];
        yield 'a built server' => [static fn(): object => TrackingFixture::builder()->build()];
        yield 'any object' => [static fn(): object => new \stdClass()];
    }

    /** @param \Closure(): object $make */
    #[DataProvider('untracked')]
    #[Group('ac-01-68')]
    public function testAnUntrackedServerIsRejected(\Closure $make): void
    {
        $server = $make();

        $error = Thrown::by(\InvalidArgumentException::class, fn() => $this->client->publishCustomEvent($server, self::PROJECT, new CustomEvent(sessionId: 'ses_1')));

        $this->assertSame(self::NOT_TRACKED, $error->getMessage());
        $this->assertNothingQueued();
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-01-70')]
    #[Group('ac-03-33')]
    #[Group('ac-01-77')]
    public function testASessionIdStringIsUsedVerbatimWithoutSdkFields(): void
    {
        $this->client->publishCustomEvent('user-session-12345', 'proj_s', new CustomEvent(resourceName: 'r'));

        $body = $this->body();
        $this->assertSame(
            ['user-session-12345', 'proj_s', Constants::CUSTOM_EVENT_TYPE, 'r'],
            [JsonAssert::at($body, 'session_id'), JsonAssert::at($body, 'project_id'), JsonAssert::at($body, 'event_type'), JsonAssert::at($body, 'resource_name')],
        );
        foreach (['sdk_language', 'agentcat_version', 'server_name', 'server_version', 'client_name', 'identify_data', 'duration', 'tags'] as $key) {
            $this->assertObjectNotHasProperty($key, $body);
        }
        $this->assertSame(["Published custom event for session user-session-12345 with type 'agentcat:custom'"], $this->logger->messages());
    }

    #[Group('ac-01-71')]
    public function testTheSessionIdOfTheEventWinsOverTheString(): void
    {
        $this->client->publishCustomEvent('ignored-string', 'proj_s', new CustomEvent(sessionId: 'from-data'));

        $this->assertSame('from-data', JsonAssert::at($this->body(), 'session_id'));
        $this->assertSame(["Published custom event for session from-data with type 'agentcat:custom'"], $this->logger->messages());
    }

    public function testAnEmptySessionIdStringPublishesWithoutASession(): void
    {
        $this->client->publishCustomEvent('', 'proj_s', new CustomEvent(sessionId: ''));

        $this->assertNull(JsonAssert::at($this->body(), 'session_id'));
        $this->assertSame([self::PUBLISHED_WITHOUT_SESSION], $this->logger->messages(), 'the string form never says that a session is missing');
    }

    /** @return iterable<string, array{?string}> */
    public static function noSessionId(): iterable
    {
        yield 'none' => [null];
        yield 'empty' => [''];
    }

    #[DataProvider('noSessionId')]
    #[Group('ac-01-72')]
    public function testATrackedServerWithoutASessionIdPublishesWithoutASession(?string $sessionId): void
    {
        $builder = $this->tracked();

        $this->client->publishCustomEvent($builder, self::PROJECT, new CustomEvent(sessionId: $sessionId));

        $this->assertNull(JsonAssert::at($this->body(), 'session_id'));
        $this->assertSame([self::NO_SESSION, self::PUBLISHED_WITHOUT_SESSION], $this->logger->messages());
    }

    #[Group('ac-01-72')]
    public function testATrackedServerWithASessionIdPublishesForIt(): void
    {
        $builder = $this->tracked();

        $this->client->publishCustomEvent($builder, self::PROJECT, new CustomEvent(sessionId: 'ses_task'));

        $this->assertSame('ses_task', JsonAssert::at($this->body(), 'session_id'));
        $this->assertSame(["Published custom event for session ses_task with type 'agentcat:custom'"], $this->logger->messages());
    }

    #[Group('ac-01-73')]
    public function testTheMessageIsTheUserIntentAndTheTimestampIsTheCallTime(): void
    {
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->client->publishCustomEvent('ses_1', 'proj_s', new CustomEvent(message: 'why'));
        $after = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $body = $this->body();
        $this->assertSame('why', JsonAssert::at($body, 'user_intent'));
        $timestamp = JsonAssert::at($body, 'timestamp');
        $this->assertIsString($timestamp);
        $sent = (float) (new \DateTimeImmutable($timestamp))->format('U.v');
        $this->assertGreaterThanOrEqual((float) $before->format('U.v') - 1.0, $sent);
        $this->assertLessThanOrEqual((float) $after->format('U.v') + 1.0, $sent);
    }

    #[Group('ac-01-74')]
    public function testOnlyValidTagsAreSentAndNoSdkTags(): void
    {
        $builder = $this->tracked();

        $this->client->publishCustomEvent($builder, self::PROJECT, new CustomEvent(sessionId: 'ses_1', tags: ['valid' => 'v', 'bad!key' => 'v']));

        $this->assertEquals((object) ['valid' => 'v'], JsonAssert::at($this->body(), 'tags'));
        $this->assertContains('Dropping invalid tag: "bad!key" — key contains invalid characters or is empty', $this->logger->messages());
    }

    #[Group('ac-01-74')]
    public function testTagsWithoutAValidEntryAreLeftOut(): void
    {
        $this->client->publishCustomEvent('ses_1', 'proj_s', new CustomEvent(tags: ['bad!key' => 'v']));
        $this->client->publishCustomEvent('ses_1', 'proj_s', new CustomEvent(tags: []));

        foreach ($this->bodies(2) as $body) {
            $this->assertObjectNotHasProperty('tags', $body);
        }
    }

    #[Group('ac-01-75')]
    public function testPropertiesAreSentOnlyWithAnEntry(): void
    {
        $this->client->publishCustomEvent('ses_1', 'proj_s', new CustomEvent(properties: []));
        $this->client->publishCustomEvent('ses_1', 'proj_s', new CustomEvent(properties: new \stdClass()));
        $this->client->publishCustomEvent('ses_1', 'proj_s', new CustomEvent(properties: 'not an object'));
        $this->client->publishCustomEvent('ses_1', 'proj_s', new CustomEvent(properties: ['a' => 1]));
        $this->client->publishCustomEvent('ses_1', 'proj_s', new CustomEvent(properties: (object) ['b' => 2]));

        $bodies = $this->bodies(5);
        foreach (array_slice($bodies, 0, 3) as $body) {
            $this->assertObjectNotHasProperty('properties', $body);
        }
        $this->assertEquals((object) ['a' => 1], JsonAssert::at($bodies, 3, 'properties'));
        $this->assertEquals((object) ['b' => 2], JsonAssert::at($bodies, 4, 'properties'));
    }

    #[Group('ac-01-76')]
    public function testWithoutEventDataOnlyTheCoreFieldsAreSent(): void
    {
        $this->client->publishCustomEvent('ses_1', 'proj_s');
        $this->client->publishCustomEvent('ses_1', 'proj_s', new CustomEvent());

        foreach ($this->bodies(2) as $body) {
            $keys = array_keys(get_object_vars($body));
            sort($keys);
            $this->assertSame(['event_type', 'id', 'project_id', 'session_id', 'timestamp'], $keys);
        }
    }

    #[Group('ac-01-77')]
    public function testATrackedServerStampsTheServerAndSdkFields(): void
    {
        $builder = $this->tracked();

        $this->client->publishCustomEvent($builder, self::PROJECT, new CustomEvent(sessionId: 'ses_1'));

        $body = $this->body();
        $this->assertSame(
            ['harness-server', '1.2.3', 'PHP', SdkVersion::get(), Constants::CUSTOM_EVENT_TYPE],
            [
                JsonAssert::at($body, 'server_name'),
                JsonAssert::at($body, 'server_version'),
                JsonAssert::at($body, 'sdk_language'),
                JsonAssert::at($body, 'agentcat_version'),
                JsonAssert::at($body, 'event_type'),
            ],
        );
        $this->assertEquals(new \stdClass(), JsonAssert::at($body, 'identify_data'));
        $this->assertObjectNotHasProperty('client_name', $body);
    }

    #[Group('ac-01-78')]
    #[Group('ac-03-32')]
    public function testATrackedServerSendsToItsTrackedProject(): void
    {
        $builder = $this->tracked();

        $this->client->publishCustomEvent($builder, 'proj_other', new CustomEvent(sessionId: 'ses_1'));

        $this->assertSame(self::PROJECT, JsonAssert::at($this->body(), 'project_id'));
    }

    #[Group('ac-01-78')]
    #[Group('ac-03-32')]
    public function testATelemetryOnlyServerExportsAndNeverPosts(): void
    {
        $builder = $this->tracked(project: '');

        $this->client->publishCustomEvent($builder, 'proj_other', new CustomEvent(sessionId: 'ses_1'));

        $this->assertTrue($this->client->flush());
        $this->assertSame([], $this->transport->deliveries);
        $this->assertCount(1, $this->exporter->events);
        $this->assertNull($this->exporter->events[0]->projectId);
        $this->assertSame(Constants::CUSTOM_EVENT_TYPE, $this->exporter->events[0]->eventType);
    }

    public function testATrackedServerWithTracingOffDropsTheEventButSaysItPublished(): void
    {
        $builder = $this->tracked(new Options(enableTracing: false));

        $this->client->publishCustomEvent($builder, self::PROJECT, new CustomEvent(sessionId: 'ses_1'));

        $this->assertNothingQueued();
        $this->assertSame(["Published custom event for session ses_1 with type 'agentcat:custom'"], $this->logger->messages());
    }

    public function testTheServerFormFillsAMissingDurationAndTheStringFormDoesNot(): void
    {
        $builder = $this->tracked();

        $this->client->publishCustomEvent($builder, self::PROJECT, new CustomEvent(sessionId: 'ses_1'));
        $this->client->publishCustomEvent('ses_1', 'proj_s', new CustomEvent());
        $this->client->publishCustomEvent('ses_1', 'proj_s', new CustomEvent(duration: 12.5));

        $duration = JsonAssert::at($this->body(0, 3), 'duration');
        $this->assertIsInt($duration);
        $this->assertGreaterThanOrEqual(0, $duration);
        $this->assertObjectNotHasProperty('duration', $this->body(1, 3));
        $this->assertSame(13, JsonAssert::at($this->body(2, 3), 'duration'), 'whole milliseconds on the wire');
    }

    #[Group('ac-01-54')]
    #[Group('ac-05-P7')]
    public function testRedactEventAppliesToTheServerFormOnlyAndRedactSensitiveInformationToNeither(): void
    {
        $builder = $this->tracked(new Options(
            redactSensitiveInformation: static fn(string $text): string => '[R]',
            redactEvent: static function (Event $event): Event {
                $event->resourceName = 'redacted';

                return $event;
            },
        ));
        $data = new CustomEvent(sessionId: 'ses_1', resourceName: 'r', parameters: ['msg' => 'secret'], message: 'why');

        $this->client->publishCustomEvent($builder, self::PROJECT, $data);
        $this->client->publishCustomEvent('ses_1', 'proj_s', $data);

        $server = $this->body(0, 2);
        $string = $this->body(1, 2);
        $this->assertSame('redacted', JsonAssert::at($server, 'resource_name'));
        $this->assertSame('r', JsonAssert::at($string, 'resource_name'));
        foreach ([$server, $string] as $body) {
            $this->assertSame('secret', JsonAssert::at($body, 'parameters', 'msg'));
            $this->assertSame('why', JsonAssert::at($body, 'user_intent'));
            $this->assertSame('ses_1', JsonAssert::at($body, 'session_id'));
        }
    }

    public function testUnderPhpUnitTheDefaultClientValidatesTheProjectAndQueuesNothing(): void
    {
        $client = new Client(ClientSeams::of($this->queue));
        $untracked = TrackingFixture::builder();

        $client->publishCustomEvent($untracked, 'proj_x');
        $client->publishCustomEvent('ses_1', 'proj_x', new CustomEvent());

        $this->assertNothingQueued();
        $this->assertSame([], $this->logger->messages());
        $error = Thrown::by(\InvalidArgumentException::class, static fn() => $client->publishCustomEvent($untracked, null));
        $this->assertSame(self::NO_PROJECT, $error->getMessage());
    }

    /** Review Focus 4: payloads that PHP cannot encode as they are. */
    public function testAPayloadPhpCannotEncodeNeverThrows(): void
    {
        $self = ['name' => 'loop'];
        $self['self'] = &$self;
        $data = new CustomEvent(parameters: ['f' => static fn(): int => 1, 'r' => STDIN, 'self' => $self], response: NAN, message: "caf\xE9");

        $this->client->publishCustomEvent('ses_1', 'proj_s', $data);
        $this->assertTrue($this->client->flush());

        $failures = array_values(array_filter($this->logger->messages(), static fn(string $line): bool => str_starts_with($line, 'Failed to ')));
        $this->assertSame([], $this->transport->deliveries, 'the cycle drops the event');
        $this->assertSame(['Failed to sanitize event: RangeException: Maximum call stack size exceeded'], $failures);
    }

    /** Review Focus 4: what remains encodable is delivered with the pipeline's markers and substitutions. */
    public function testAPayloadWithoutACycleIsDeliveredWithMarkers(): void
    {
        $data = new CustomEvent(parameters: ['f' => static fn(): int => 1, 'r' => STDIN], response: NAN, message: "caf\xE9");

        $this->client->publishCustomEvent('ses_1', 'proj_s', $data);

        $body = $this->body();
        $intent = JsonAssert::at($body, 'user_intent');
        $this->assertIsString($intent);
        $this->assertStringContainsString("\u{FFFD}", $intent);
        $this->assertSame('[Resource: stream]', JsonAssert::at($body, 'parameters', 'r'));
    }

    public function testAFailureAfterValidationIsLoggedAndSwallowed(): void
    {
        $object = new \stdClass();
        $publisher = new Publisher($this->queue);
        TrackedServers::add($object, new TrackedServer(self::PROJECT, new Options(), static fn(): array => throw new \RuntimeException('boom'), $publisher));

        $this->client->publishCustomEvent($object, self::PROJECT, new CustomEvent(sessionId: 'ses_1'));

        $this->assertNothingQueued();
        $this->assertSame(['Warning: Failed to publish custom event - RuntimeException: boom'], $this->logger->messages());
    }

    /** Ruling R1: the event of a server goes out through the client that tracked it. */
    public function testATrackedServerPublishesThroughTheClientThatTrackedIt(): void
    {
        $builder = $this->tracked();
        $otherTransport = new RecordingTransport();
        $other = new Client(ClientSeams::of(new EventQueue(transport: $otherTransport), static fn(): bool => true));

        $other->publishCustomEvent($builder, self::PROJECT, new CustomEvent(sessionId: 'ses_1'));

        $this->assertTrue($other->flush());
        $this->assertSame([], $otherTransport->deliveries);
        $this->assertSame('ses_1', JsonAssert::at($this->body(), 'session_id'));
    }

    /** Ruling R3, `01` AC-69: the server built from a tracked Builder is accepted too. */
    #[Group('ac-01-69')]
    public function testTheServerBuiltFromATrackedBuilderIsAccepted(): void
    {
        $builder = TrackingFixture::builder();
        $this->client->track($builder, self::PROJECT);
        $server = $builder->build();
        $this->logger->records = [];

        $this->client->publishCustomEvent($server, 'proj_other', new CustomEvent(sessionId: 'ses_1'));
        $this->client->publishCustomEvent($server, 'proj_other', new CustomEvent(sessionId: 'ses_2'));

        $bodies = $this->bodies(2);
        $this->assertSame([self::PROJECT, 'harness-server', 'ses_1'], [JsonAssert::at($bodies, 0, 'project_id'), JsonAssert::at($bodies, 0, 'server_name'), JsonAssert::at($bodies, 0, 'session_id')]);
        $this->assertSame('ses_2', JsonAssert::at($bodies, 1, 'session_id'));
        $this->assertSame(TrackedServers::get($builder), TrackedServers::get($server), 'kept for the server');
    }

    /** Ruling R3: the state kept for a server is used without reading the server again. */
    public function testTheStateKeptForAServerIsUsedFirst(): void
    {
        $server = new Server((new \ReflectionClass(Protocol::class))->newInstanceWithoutConstructor()); // unreadable
        TrackedServers::add($server, new TrackedServer(self::PROJECT, new Options(), static fn(): array => ['kept', '1.0'], new Publisher($this->queue)));

        $this->client->publishCustomEvent($server, self::PROJECT, new CustomEvent(sessionId: 'ses_1'));

        $this->assertSame('kept', JsonAssert::at($this->body(), 'server_name'));
        $this->assertSame(["Published custom event for session ses_1 with type 'agentcat:custom'"], $this->logger->messages());
    }

    /** Ruling R3: a server whose members AgentCat cannot read logs the compatibility line once, and nothing else. */
    public function testAServerWhoseInternalsChangedLogsTheCompatibilityLineOnce(): void
    {
        $server = new Server((new \ReflectionClass(Protocol::class))->newInstanceWithoutConstructor());

        $this->client->publishCustomEvent($server, self::PROJECT, new CustomEvent(sessionId: 'ses_1'));
        $this->client->publishCustomEvent($server, self::PROJECT, new CustomEvent(sessionId: 'ses_1'));

        $this->assertNothingQueued();
        $this->assertSame([['level' => LogLevel::WARNING, 'message' => self::COMPATIBILITY]], $this->logger->records);
        $this->assertNull(TrackedServers::get($server));
    }

    /** Tracks a fresh Builder for `harness-server` 1.2.3 and builds it, so its server info is known; clears the log. */
    private function tracked(Options $options = new Options(), string $project = self::PROJECT): Builder
    {
        $builder = TrackingFixture::builder();
        $this->client->track($builder, $project, $options);
        $builder->build();
        $this->logger->records = [];

        return $builder;
    }

    /**
     * Delivers everything queued and decodes the bodies; fails unless exactly `$count` were posted.
     *
     * @return list<\stdClass>
     */
    private function bodies(int $count): array
    {
        $this->assertTrue($this->client->flush());
        $this->assertCount($count, $this->transport->deliveries);
        $bodies = [];
        foreach ($this->transport->deliveries as $delivery) {
            $body = Json::decode($delivery->body);
            $this->assertInstanceOf(\stdClass::class, $body);
            $bodies[] = $body;
        }

        return $bodies;
    }

    /** Delivers everything queued and decodes the body at `$index`; fails unless exactly `$of` were posted. */
    private function body(int $index = 0, int $of = 1): \stdClass
    {
        $body = $this->bodies($of)[$index] ?? null;
        $this->assertInstanceOf(\stdClass::class, $body);

        return $body;
    }

    private function assertNothingQueued(): void
    {
        $this->assertSame(['queueLength' => 0, 'activeRequests' => 0], $this->queue->stats());
        $this->assertTrue($this->queue->flush());
        $this->assertSame([], $this->exporter->events);
    }
}
