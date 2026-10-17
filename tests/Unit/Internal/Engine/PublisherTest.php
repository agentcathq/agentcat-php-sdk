<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Engine;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Options;
use AgentCat\Tests\Support\RecordingExporter;
use AgentCat\Tests\Support\RecordingTransport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class PublisherTest extends TestCase
{
    private RecordingTransport $transport;

    private RecordingExporter $exporter;

    private EventQueue $queue;

    private Publisher $publisher;

    #[\Override]
    protected function setUp(): void
    {
        $this->transport = new RecordingTransport();
        $this->exporter = new RecordingExporter();
        $this->queue = new EventQueue(transport: $this->transport, exporters: $this->exporter);
        $this->publisher = new Publisher($this->queue, fn(): \DateTimeImmutable => $this->at('2026-10-17T12:00:00.500Z'));
    }

    public function testPublishStampsAndQueuesTheEvent(): void
    {
        $this->publisher->publishToolCall($this->server('proj_1'), $this->event(), 'c', '1', []);
        $this->queue->flush();

        $event = $this->exported();
        $this->assertSame('PHP', $event->sdkLanguage);
        $this->assertSame('srv', $event->serverName);
        $this->assertSame('1.0', $event->serverVersion);
        $this->assertSame('c', $event->clientName);
        $this->assertSame('1', $event->clientVersion);
        $this->assertEquals(new \stdClass(), $event->identifyActorData);
        $this->assertSame('proj_1', $event->projectId);
        $this->assertSame(250, $event->duration);
        $this->assertCount(1, $this->transport->deliveries);
    }

    public function testAGivenDurationStaysAndZeroIsReplaced(): void
    {
        $this->publisher->publishToolCall($this->server('proj_1'), $this->event(42), null, null, []);
        $this->publisher->publishToolCall($this->server('proj_1'), $this->event(0), null, null, []);
        $this->publisher->publishToolCall($this->server('proj_1'), $this->event(0.0), null, null, []);
        $this->queue->flush();

        $this->assertSame([42, 250, 250], array_map(static fn(Event $e): int|float|null => $e->duration, $this->exporter->events));
    }

    #[Group('ac-05-R21')]
    #[Group('ac-01-53')]
    public function testQueuedEventCarriesTheRedactorsAndRunsPendingFirst(): void
    {
        $seen = [];
        $options = new Options(
            redactSensitiveInformation: static fn(string $s): string => '[R]',
            redactEvent: static function (Event $event) use (&$seen): Event {
                $seen[] = $event->tags;

                return $event;
            },
        );
        $event = $this->event();
        $event->parameters = (object) ['request' => (object) ['params' => (object) ['arguments' => (object) ['msg' => 'hi']]]];
        $pending = [static function (Event $e): void {
            $e->tags = ['from' => 'pending'];
        }];

        $this->publisher->publishToolCall($this->server('proj_1', $options), $event, null, null, $pending);
        $this->queue->flush();

        $this->assertSame([['from' => 'pending']], $seen);
        $this->assertEquals(
            (object) ['request' => (object) ['params' => (object) ['arguments' => (object) ['msg' => '[R]']]]],
            $this->exported()->parameters,
        );
    }

    public function testTelemetryOnlyServerExportsWithoutDelivery(): void
    {
        $this->publisher->publishToolCall($this->server(''), $this->event(), null, null, []);
        $this->queue->flush();

        $this->assertNull($this->exported()->projectId);
        $this->assertSame([], $this->transport->deliveries);
    }

    public function testTracingOffQueuesNothing(): void
    {
        $this->publisher->publishToolCall($this->server('proj_1', new Options(enableTracing: false)), $this->event(), null, null, []);

        $this->assertSame(0, $this->queue->stats()['queueLength']);
    }

    public function testElapsedMsCountsWholeMilliseconds(): void
    {
        $this->assertSame(250, Publisher::elapsedMs($this->at('2026-10-17T12:00:00.250999Z'), $this->at('2026-10-17T12:00:00.500001Z')));
        $this->assertSame(1500, Publisher::elapsedMs($this->at('2026-10-17T12:00:00.000Z'), $this->at('2026-10-17T12:00:01.500Z')));
        $this->assertSame(-5, Publisher::elapsedMs($this->at('2026-10-17T12:00:00.005Z'), $this->at('2026-10-17T12:00:00.000Z')));
    }

    public function testPumpDelegatesToTheQueue(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        $publisher = new Publisher($queue);
        $publisher->publishToolCall($this->server('proj_1'), $this->event(), null, null, []);

        $this->assertSame(1, $queue->stats()['queueLength']);
        $publisher->pump();

        $this->assertCount(1, $transport->deliveries);
        $this->assertSame($queue, $publisher->queue());
    }

    public function testTheDefaultClockIsNow(): void
    {
        $queue = new EventQueue(transport: $this->transport, exporters: $this->exporter);
        $event = $this->event();
        $event->timestamp = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        (new Publisher($queue))->publishToolCall($this->server('proj_1'), $event, null, null, []);
        $queue->flush();

        $duration = $this->exported()->duration;
        $this->assertIsInt($duration);
        $this->assertGreaterThanOrEqual(0, $duration);
        $this->assertLessThan(5000, $duration);
    }

    private function exported(): Event
    {
        $events = $this->exporter->events;
        $this->assertGreaterThanOrEqual(1, count($events));

        return $events[0] ?? new Event();
    }

    private function server(string $projectId, ?Options $options = null): TrackedServer
    {
        return new TrackedServer($projectId, $options ?? new Options(), static fn(): array => ['srv', '1.0']);
    }

    private function event(int|float|null $duration = null): Event
    {
        return new Event(sessionId: 'ses_x', eventType: 'mcp:tools/call', timestamp: $this->at('2026-10-17T12:00:00.250Z'), duration: $duration);
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time);
    }
}
