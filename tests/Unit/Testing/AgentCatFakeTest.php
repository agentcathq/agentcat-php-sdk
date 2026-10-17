<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Testing;

use AgentCat\AgentCat;
use AgentCat\Client;
use AgentCat\CustomEvent;
use AgentCat\Event;
use AgentCat\Identity;
use AgentCat\Internal\ClientSeams;
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
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\Tests\Support\Thrown;
use AgentCat\ToolCall;
use Mcp\Server\Builder;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/** Customer style: everything goes through `AgentCat`. */
final class AgentCatFakeTest extends TestCase
{
    private const string PROJECT = 'proj_abcdefghijklmnopqrstuvwxyz0';

    private Environment $environment;

    private MemoryLogger $logger;

    /** The test's own `$HOME`, from `freshHome()`; removed in `tearDown()`. */
    private ?string $freshHome = null;

    private string $home = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->environment = Environment::clear('AGENTCAT_ENABLED', 'AGENTCAT_API_URL', 'MCPCAT_API_URL', 'AGENTCAT_PROJECT_ID');
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        $this->home = $this->home();
    }

    #[\Override]
    protected function tearDown(): void
    {
        TrackedServers::resetIncomplete();
        AgentCat::setClient(null);
        EventQueue::setGlobal(null);
        Log::reset();
        $this->environment->restore();
        putenv('HOME=' . $this->home);
        if ($this->freshHome !== null) {
            if (is_file($this->freshHome . '/agentcat.log')) {
                unlink($this->freshHome . '/agentcat.log');
            }
            rmdir($this->freshHome);
        }
    }

    /** D31, Review Focus 1: PHPUnit is loaded and `AGENTCAT_ENABLED` is unset. */
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

    /** D31: the fake records and sends nothing. */
    public function testNothingGoesToTheNetwork(): void
    {
        putenv('AGENTCAT_API_URL=' . MockCollector::closedPortUrl());
        $fake = AgentCat::fake();
        $this->trackedHarness()->callTool('echo', ['msg' => 'hi']);

        $fake->assertTrackedCount(1);
        $this->assertNotSame([], $fake->logs());
        $this->assertSame([], array_filter($fake->logs(), static fn(string $m): bool => str_contains($m, 'Failed to send event')));
        putenv('AGENTCAT_API_URL');
    }

    /** A customer suite chose no logger: under the fake, nothing goes to `$HOME/agentcat.log`. */
    public function testAFakeOnlyTestWritesNoLogFile(): void
    {
        Log::reset();
        $home = $this->freshHome();
        $fake = AgentCat::fake();

        $this->trackedHarness()->callTool('echo', ['msg' => 'hi']);
        AgentCat::publishCustomEvent('s-1', self::PROJECT, new CustomEvent(message: 'm'));

        $fake->assertTrackedCount(1);
        $this->assertFileDoesNotExist($home . '/agentcat.log');
        $this->assertSame([
            'AgentCat setup started | project ' . self::PROJECT . ' | sdk mcp-sdk/builder | signals mcpSdkBuilder',
            'AgentCat setup complete | project ' . self::PROJECT . ' | tracing=true context=true reportMissing=true exporters=0',
            'Rebuilt injection registries on demand (tools/call before tools/list on this instance)',
            "Published custom event for session s-1 with type 'agentcat:custom'",
        ], $fake->logs());
    }

    public function testLogsHoldTheLinesOfTheTimeTheFakeWasTheSharedClient(): void
    {
        Log::write('before');
        $first = AgentCat::fake();
        Log::write('first');
        $second = AgentCat::fake();
        Log::write('second');
        AgentCat::setClient(null);
        Log::write('after');

        $this->assertSame(['first'], $first->logs());
        $this->assertSame(['second'], $second->logs(), 'a second fake starts its own logs');
        $this->assertSame(['before', 'after'], $this->logger->messages(), 'the routing from before the first fake is back');
    }

    /** D38: the first Builder logger adopted gets every later line, but not while a fake is the shared client. */
    public function testABuildersLoggerTrackedUnderTheFakeGetsNoAgentCatLine(): void
    {
        Log::reset();
        $home = $this->freshHome();
        $customers = new MemoryLogger();
        $fake = AgentCat::fake();
        $builder = TrackingFixture::builder()->setLogger($customers);

        AgentCat::track($builder, self::PROJECT);
        AgentCat::publishCustomEvent($builder, self::PROJECT, new CustomEvent(sessionId: 'ses_1'));
        AgentCat::setClient(null);

        $this->assertSame([], $customers->messages());
        $this->assertCount(3, $fake->logs());
        $later = new MemoryLogger();
        Log::adopt($later);
        Log::write('after the fake');
        $this->assertSame(['after the fake'], $later->messages(), 'adoption resumes after the fake');
        $this->assertFileDoesNotExist($home . '/agentcat.log');
    }

    public function testReplacingTheFakeByAnotherClientRestoresTheRouting(): void
    {
        $fake = AgentCat::fake();
        AgentCat::setClient(new Client(ClientSeams::of(new EventQueue(transport: new RecordingTransport()), static fn(): bool => true)));

        Log::write('after');

        $this->assertSame([], $fake->logs());
        $this->assertSame(['after'], $this->logger->messages());
    }

    public function testLogsRunTheWaitingHooksFirst(): void
    {
        $fake = AgentCat::fake();
        $harness = $this->trackedHarness(new Options(eventTags: static fn(): never => throw new \RuntimeException('tags down')));

        $harness->callTool('echo', ['msg' => 'hi']);

        $this->assertContains('eventTags callback error: RuntimeException: tags down', $fake->logs());
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

    /** Ruling R4: `AGENTCAT_ENABLED=false` (D44) does not turn the fake off. */
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

    public function testEveryPassingAssertionCountsExactlyOnePhpunitAssertion(): void
    {
        $fake = AgentCat::fake();
        $fake->assertNothingTracked();
        AgentCat::publishCustomEvent('s-1', self::PROJECT, new CustomEvent(resourceName: 'c'));
        $this->trackedHarness()->callTool('echo', ['msg' => 'hi']);
        $fake->events();

        foreach ([
            'assertTracked' => static fn() => $fake->assertTracked(),
            'assertTrackedCount' => static fn() => $fake->assertTrackedCount(1),
            'assertCustomEventPublished' => static fn() => $fake->assertCustomEventPublished(),
        ] as $name => $assertion) {
            $before = Assert::getCount();
            $assertion();
            $this->assertSame(1, Assert::getCount() - $before, $name);
        }
    }

    public function testAssertNothingTrackedCountsOnePhpunitAssertion(): void
    {
        $fake = AgentCat::fake();
        $before = Assert::getCount();

        $fake->assertNothingTracked();

        $this->assertSame(1, Assert::getCount() - $before);
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

    /** Points `$HOME` at a new, empty directory for this test. */
    private function freshHome(): string
    {
        $this->freshHome = $this->home . '/fake-' . bin2hex(random_bytes(4));
        mkdir($this->freshHome);
        putenv('HOME=' . $this->freshHome);

        return $this->freshHome;
    }

    private function home(): string
    {
        $home = getenv('HOME');
        $this->assertIsString($home);

        return $home;
    }
}
