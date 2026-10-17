<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Testing;

use AgentCat\AgentCat;
use AgentCat\CustomEvent;
use AgentCat\Event;
use AgentCat\Identity;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\Logging\Log;
use AgentCat\Options;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use AgentCat\Tests\Support\McpSdk\Tools;
use AgentCat\Tests\Support\McpSdk\TrackingFixture;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\Thrown;
use AgentCat\ToolCall;
use Mcp\Server\Builder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Customer style: everything goes through `AgentCat`. */
final class AgentCatFakeTest extends TestCase
{
    private const string PROJECT = 'proj_abcdefghijklmnopqrstuvwxyz0';

    private Environment $environment;

    private MemoryLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->environment = Environment::clear('AGENTCAT_ENABLED', 'AGENTCAT_API_URL', 'MCPCAT_API_URL', 'AGENTCAT_PROJECT_ID');
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
    }

    #[\Override]
    protected function tearDown(): void
    {
        TrackedServers::resetIncomplete();
        AgentCat::setClient(null);
        EventQueue::setGlobal(null);
        Log::reset();
        $this->environment->restore();
    }

    #[Group('ac-01-69')]
    public function testWithoutFakeTheSdkStaysOffInATestSuite(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);

        $this->assertSame($builder, AgentCat::track($builder, self::PROJECT));
        AgentCat::publishCustomEvent($builder, self::PROJECT, new CustomEvent(message: 'm'));

        $this->assertSame(['echo'], $this->toolNames($builder), 'no get_more_tools was injected');
        $this->assertSame(['queueLength' => 0, 'activeRequests' => 0], EventQueue::global()->stats());
        $this->assertFileDoesNotExist($this->home() . '/agentcat.log');
    }

    public function testFakeInstallsItsClient(): void
    {
        $fake = AgentCat::fake();

        $this->assertSame($fake->client(), AgentCat::client());
    }

    public function testATrackedToolCallIsRecordedWithItsHooksApplied(): void
    {
        $fake = AgentCat::fake();
        $harness = $this->trackedHarness(new Options(identify: static fn(ToolCall $call): Identity => new Identity('u1')));

        $harness->callTool('echo', ['msg' => 'hi']);

        $fake->assertTracked(static fn(Event $e): bool => $e->resourceName === 'echo' && $e->identifyActorGivenId === 'u1');
        $fake->assertTrackedCount(1);
        $events = $fake->events();
        $this->assertCount(1, $events);
        $this->assertMatchesRegularExpression('/\Aevt_[0-9A-Za-z]{27}\z/', $events[0]->id);
    }

    public function testAssertTrackedWithoutAFilterNeedsOneToolCall(): void
    {
        $fake = AgentCat::fake();
        $message = $this->failureOf(static fn() => $fake->assertTracked());
        $this->assertSame('Failed asserting that a tool call matching the filter was tracked.', $message);

        $this->trackedHarness()->callTool('echo', ['msg' => 'hi']);
        $fake->assertTracked();
    }

    public function testAssertNothingTrackedPassesOnAnEmptyFakeAndFailsAfterACall(): void
    {
        $fake = AgentCat::fake();
        $fake->assertNothingTracked();

        $this->trackedHarness()->callTool('echo', ['msg' => 'hi']);

        $this->assertSame(
            'Failed asserting that nothing was tracked: 1 event(s) were recorded.',
            $this->failureOf(static fn() => $fake->assertNothingTracked()),
        );
    }

    public function testFailingAssertionsSayWhy(): void
    {
        $fake = AgentCat::fake();
        $this->trackedHarness()->callTool('echo', ['msg' => 'hi']);

        $this->assertSame(
            'Failed asserting that a tool call matching the filter was tracked.',
            $this->failureOf(static fn() => $fake->assertTracked(static fn(Event $e): bool => false)),
        );
        $this->assertSame(
            'Failed asserting that 2 tool call(s) were tracked: 1 were.',
            $this->failureOf(static fn() => $fake->assertTrackedCount(2)),
        );
        $this->assertSame(
            'Failed asserting that a custom event matching the filter was published.',
            $this->failureOf(static fn() => $fake->assertCustomEventPublished()),
        );
    }

    public function testACustomEventIsNotAToolCall(): void
    {
        $fake = AgentCat::fake();

        AgentCat::publishCustomEvent('s-1', self::PROJECT, new CustomEvent(resourceName: 'c'));

        $fake->assertCustomEventPublished(static fn(Event $e): bool => $e->resourceName === 'c');
        $fake->assertCustomEventPublished();
        $fake->assertTrackedCount(0);
        $this->assertSame(
            'Failed asserting that a custom event matching the filter was published.',
            $this->failureOf(static fn() => $fake->assertCustomEventPublished(static fn(Event $e): bool => $e->resourceName === 'other')),
        );
    }

    #[Group('ac-01-69')]
    public function testNothingGoesToTheNetwork(): void
    {
        putenv('AGENTCAT_API_URL=' . MockCollector::closedPortUrl());
        $fake = AgentCat::fake();
        $this->trackedHarness()->callTool('echo', ['msg' => 'hi']);

        $fake->assertTrackedCount(1);
        $this->assertSame([], array_filter($this->logger->messages(), static fn(string $m): bool => str_contains($m, 'Failed to send event')));
        putenv('AGENTCAT_API_URL');
    }

    public function testTelemetryOnlyServersAreRecordedToo(): void
    {
        $fake = AgentCat::fake();
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        AgentCat::track($builder, '');
        $harness = new HttpHarness($builder->build());
        $harness->initialize();

        $harness->callTool('echo', ['msg' => 'hi']);

        $fake->assertTrackedCount(1);
    }

    #[Group('ac-01-69')]
    public function testTheFakeRecordsWhateverAgentcatEnabledSays(): void
    {
        putenv('AGENTCAT_ENABLED=false');
        $fake = AgentCat::fake();
        $this->trackedHarness()->callTool('echo', ['msg' => 'hi']);

        $fake->assertTrackedCount(1);
        putenv('AGENTCAT_ENABLED');
    }

    public function testASecondFakeStartsEmpty(): void
    {
        $first = AgentCat::fake();
        $this->trackedHarness()->callTool('echo', ['msg' => 'hi']);
        $first->assertTrackedCount(1);

        $second = AgentCat::fake();

        $second->assertNothingTracked();
        $this->assertNotSame($first->client(), $second->client());
    }

    private function trackedHarness(?Options $options = null): HttpHarness
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        AgentCat::track($builder, self::PROJECT, $options);
        $harness = new HttpHarness($builder->build());
        $harness->initialize();

        return $harness;
    }

    /** @return list<string> */
    private function toolNames(Builder $builder): array
    {
        $harness = new HttpHarness($builder->build());
        $harness->initialize();
        $tools = $harness->listTools()->tools ?? null;
        $this->assertIsList($tools);

        return array_map(static fn(mixed $t): string => $t instanceof \stdClass && is_string($t->name ?? null) ? $t->name : '', $tools);
    }

    /** @param \Closure(): mixed $code */
    private function failureOf(\Closure $code): string
    {
        $failure = Thrown::by(\Throwable::class, $code);
        $this->assertStringEndsWith('AssertionFailedError', $failure::class);

        return $failure->getMessage();
    }

    private function home(): string
    {
        $home = getenv('HOME');
        $this->assertIsString($home);

        return $home;
    }
}
