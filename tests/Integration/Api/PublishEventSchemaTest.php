<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Api;

use AgentCat\Event;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Errors\ExceptionCapture;
use AgentCat\Internal\Events\SessionInfo;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Pipeline\EventProcessor;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Wire\WireEncoder;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\OpenApiSchema;
use AgentCat\Tests\Support\WireEvents;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class PublishEventSchemaTest extends TestCase
{
    private const string LIVE_URL = 'https://api.agentcat.com/v1/schema/public/?format=json';

    #[\Override]
    protected function setUp(): void
    {
        Log::setLogger(new MemoryLogger());
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    /** @return iterable<string, array{string}> body JSON per event */
    public static function bodies(): iterable
    {
        yield 'g1 tool call' => [WireEncoder::encode(WireEvents::g1())];
        yield 'g2 identified tool call' => [WireEncoder::encode(WireEvents::g2())];
        yield 'g3 errored tool call' => [WireEncoder::encode(WireEvents::g3())];
        yield 'g4 custom event' => [WireEncoder::encode(WireEvents::g4())];

        $anonymous = new Event(
            id: 'evt_anon',
            sessionId: 'ses_2nH0cWzQ4m8LkP1sD7fG3hJ9kL0',
            projectId: 'proj_1',
            eventType: Constants::TOOLS_CALL_EVENT_TYPE,
            timestamp: new \DateTimeImmutable('2026-10-16T12:00:00.000Z'),
            duration: 5,
            resourceName: 'ping',
            parameters: Json::decode('{"request":{"method":"tools/call"},"extra":{}}'),
            response: Json::decode('{"content":[]}'),
            tags: ['agentcat_session_id_source' => 'minted'],
        );
        (new SessionInfo('srv', '1.0.0', 'client', '2.0.0'))->stamp($anonymous);
        yield 'stamped anonymous tool call' => [self::processed($anonymous)];

        $errored = new Event(
            id: 'evt_err',
            sessionId: 'ses_2nH0cWzQ4m8LkP1sD7fG3hJ9kL0',
            projectId: 'proj_1',
            eventType: Constants::TOOLS_CALL_EVENT_TYPE,
            timestamp: new \DateTimeImmutable('2026-10-16T12:00:00.000Z'),
            resourceName: 'boom',
            isError: true,
            error: ExceptionCapture::fromThrowable(new \RuntimeException('x')),
        );
        yield 'errored tool call' => [self::processed($errored)];

        $custom = new Event(
            id: 'evt_custom',
            sessionId: '',
            projectId: 'proj_1',
            eventType: Constants::CUSTOM_EVENT_TYPE,
            timestamp: new \DateTimeImmutable('2026-10-16T12:00:00.000Z'),
            resourceName: 'checkout',
        );
        yield 'custom event' => [self::processed($custom)];

        $big = new Event(
            id: 'evt_big',
            sessionId: 'ses_2nH0cWzQ4m8LkP1sD7fG3hJ9kL0',
            projectId: 'proj_1',
            eventType: Constants::TOOLS_CALL_EVENT_TYPE,
            timestamp: new \DateTimeImmutable('2026-10-16T12:00:00.000Z'),
            resourceName: 'big',
            parameters: ['text' => str_repeat('a', 300_000)],
        );
        yield 'truncated oversized parameter' => [self::processed($big)];

        $dirty = new Event(
            id: 'evt_dirty',
            sessionId: 'ses_2nH0cWzQ4m8LkP1sD7fG3hJ9kL0',
            projectId: 'proj_1',
            eventType: Constants::TOOLS_CALL_EVENT_TYPE,
            timestamp: new \DateTimeImmutable('2026-10-16T12:00:00.000Z'),
            resourceName: 'dirty',
            response: ['content' => [['type' => 'text', 'text' => "bad \xB1"]]],
            tags: ['label' => "caf\xE9"],
            properties: ['ratio' => NAN],
        );
        yield 'non-finite number and invalid UTF-8' => [WireEncoder::encode($dirty)];
    }

    private static function processed(Event $event): string
    {
        $out = (new EventProcessor())->process(new QueuedEvent($event));
        self::assertInstanceOf(Event::class, $out);

        return WireEncoder::encode($out);
    }

    #[Group('ac-07-20')]
    #[MaximumDuration(5000)]
    public function testFixtureChecksum(): void
    {
        $source = file_get_contents($this->fixtureDir() . '/SOURCE.md');
        $this->assertIsString($source);
        $this->assertSame(1, preg_match('/^([0-9a-f]{64})  tests\/Fixtures\/schema\/publish-event-request\.json$/m', $source, $m));
        $this->assertSame($m[1], hash_file('sha256', $this->fixtureDir() . '/publish-event-request.json'));
    }

    #[DataProvider('bodies')]
    #[Group('ac-07-20')]
    #[Group('ac-07-16')]
    #[MaximumDuration(5000)]
    public function testBodiesMatchSnapshotSchema(string $body): void
    {
        $this->assertValid($body, OpenApiSchema::toJsonSchema($this->snapshot()));
        $decoded = Json::decode($body);
        $this->assertInstanceOf(\stdClass::class, $decoded);
        $this->assertNotSame('', $decoded->session_id ?? null);
    }

    #[DataProvider('invalidBodies')]
    #[Group('ac-07-20')]
    #[MaximumDuration(5000)]
    public function testValidatorRejectsBadBodies(string $body): void
    {
        $result = (new Validator())->validate(Json::decode($body), OpenApiSchema::toJsonSchema($this->snapshot()));
        $this->assertFalse($result->isValid());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBodies(): iterable
    {
        yield 'tags as list' => ['{"project_id":"proj_1","tags":[]}'];
        yield 'identify_data as list' => ['{"project_id":"proj_1","identify_data":[]}'];
        yield 'camelCase key' => ['{"project_id":"proj_1","sessionId":"x"}'];
        yield 'unknown event type' => ['{"project_id":"proj_1","event_type":"nope"}'];
    }

    #[Group('ac-07-20')]
    #[MaximumDuration(5000)]
    public function testValidatorAcceptsSchemaAllowedBodies(): void
    {
        $schema = OpenApiSchema::toJsonSchema($this->snapshot());
        $validator = new Validator();
        $this->assertTrue($validator->validate(Json::decode('{"project_id":"proj_1","session_id":""}'), $schema)->isValid());
        $this->assertTrue($validator->validate(Json::decode('{"project_id":"proj_1","session_id":null}'), $schema)->isValid());
        $this->assertTrue($validator->validate(Json::decode('{"project_id":"proj_1","event_type":"agentcat:custom"}'), $schema)->isValid());
    }

    #[Group('ac-07-20')]
    #[MaximumDuration(5000)]
    public function testConversionExtractsAndTransforms(): void
    {
        $document = Json::decode('{"paths":{"/v1/public/events/":{"post":{"requestBody":{"content":{"application/json":{"schema":{"type":"object","properties":{"a":{"type":"string","nullable":true},"event_type":{"type":"string","enum":["x"]}}}}}}}}}}');
        $this->assertInstanceOf(\stdClass::class, $document);
        $schema = OpenApiSchema::toJsonSchema(OpenApiSchema::extract($document));
        JsonAssert::assertSameJson(
            '{"type":"object","properties":{"a":{"type":["string","null"]},"event_type":{"type":"string","enum":["x","agentcat:custom"]}},"additionalProperties":false}',
            (string) json_encode($schema),
        );
    }

    #[Group('ac-07-20')]
    #[MaximumDuration(5000)]
    public function testExtractFailsOnMissingPointer(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        OpenApiSchema::extract(new \stdClass());
    }

    #[Group('network')]
    #[MaximumDuration(15000)]
    public function testLiveSchemaMatchesSnapshot(): void
    {
        $live = OpenApiSchema::extract($this->fetchLive());
        JsonAssert::assertSameJson((string) json_encode($this->snapshot()), (string) json_encode($live));
    }

    #[DataProvider('bodies')]
    #[Group('network')]
    #[MaximumDuration(15000)]
    public function testBodiesMatchLiveSchema(string $body): void
    {
        $this->assertValid($body, OpenApiSchema::toJsonSchema(OpenApiSchema::extract($this->fetchLive())));
    }

    private function assertValid(string $body, \stdClass $schema): void
    {
        $result = (new Validator())->validate(Json::decode($body), $schema);
        $error = $result->error();
        $this->assertTrue($result->isValid(), $error instanceof \Opis\JsonSchema\Errors\ValidationError ? (string) json_encode((new ErrorFormatter())->format($error)) : '');
    }

    private function fetchLive(): \stdClass
    {
        $json = file_get_contents(self::LIVE_URL, false, stream_context_create(['http' => ['timeout' => 10]]));
        $this->assertIsString($json);
        $document = Json::decode($json);
        $this->assertInstanceOf(\stdClass::class, $document);

        return $document;
    }

    private function snapshot(): \stdClass
    {
        $json = file_get_contents($this->fixtureDir() . '/publish-event-request.json');
        $this->assertIsString($json);
        $schema = Json::decode($json);
        $this->assertInstanceOf(\stdClass::class, $schema);

        return $schema;
    }

    private function fixtureDir(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/schema';
    }
}
