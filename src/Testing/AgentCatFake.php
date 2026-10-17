<?php

declare(strict_types=1);

namespace AgentCat\Testing;

use AgentCat\Client;
use AgentCat\Event;
use AgentCat\Internal\ClientSeams;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Testing\Internal\InstantTransport;
use AgentCat\Testing\Internal\RecordingSink;
use PHPUnit\Framework\Assert;

/**
 * The test double of AgentCat for your own test suite: `AgentCat::fake()` returns one. It records every event
 * (after hooks, redaction and truncation) and sends nothing over the network.
 *
 * Assertions use PHPUnit when it is loaded and throw `\AssertionError` otherwise.
 */
final readonly class AgentCatFake
{
    private RecordingSink $sink;

    private Client $client;

    public function __construct()
    {
        $this->sink = new RecordingSink();
        $this->client = new Client(ClientSeams::of(
            new EventQueue(transport: new InstantTransport(), exporters: $this->sink),
            static fn(): bool => true,
        ));
    }

    /** The client that records. `AgentCat::fake()` already installed it with `AgentCat::setClient()`. */
    public function client(): Client
    {
        return $this->client;
    }

    /**
     * Every recorded event, in publish order: hooks applied, redacted, truncated. Calling it runs the hooks that
     * are still waiting.
     *
     * @return list<Event>
     */
    public function events(): array
    {
        $this->client->flush();

        return $this->sink->events;
    }

    /**
     * Asserts that at least one tool call (`mcp:tools/call`) was tracked, and one matches `$filter` when given.
     *
     * @param (\Closure(Event): bool)|null $filter
     */
    public function assertTracked(?\Closure $filter = null): void
    {
        $this->check($this->matching($this->ofType(Constants::TOOLS_CALL_EVENT_TYPE), $filter) !== [], 'Failed asserting that a tool call matching the filter was tracked.');
    }

    /** Asserts that no event of any type was recorded. */
    public function assertNothingTracked(): void
    {
        $count = count($this->events());
        $this->check($count === 0, sprintf('Failed asserting that nothing was tracked: %d event(s) were recorded.', $count));
    }

    /** Asserts the number of tracked tool calls (`mcp:tools/call`); custom events do not count. */
    public function assertTrackedCount(int $count): void
    {
        $actual = count($this->ofType(Constants::TOOLS_CALL_EVENT_TYPE));
        $this->check($actual === $count, sprintf('Failed asserting that %d tool call(s) were tracked: %d were.', $count, $actual));
    }

    /**
     * Asserts that at least one custom event (`agentcat:custom`) was published, and one matches `$filter` when given.
     *
     * @param (\Closure(Event): bool)|null $filter
     */
    public function assertCustomEventPublished(?\Closure $filter = null): void
    {
        $this->check($this->matching($this->ofType(Constants::CUSTOM_EVENT_TYPE), $filter) !== [], 'Failed asserting that a custom event matching the filter was published.');
    }

    /** @return array<int, Event> */
    private function ofType(string $eventType): array
    {
        return array_filter($this->events(), static fn(Event $e): bool => $e->eventType === $eventType);
    }

    /**
     * @param array<int, Event>            $events
     * @param (\Closure(Event): bool)|null $filter
     *
     * @return array<int, Event>
     */
    private function matching(array $events, ?\Closure $filter): array
    {
        return array_filter($events, static fn(Event $e): bool => !$filter instanceof \Closure || $filter($e));
    }

    private function check(bool $condition, string $message): void
    {
        if ($condition) {
            return;
        }
        if (class_exists(Assert::class)) {
            Assert::fail($message); // assertTrue() would append "Failed asserting that false is true."
        }

        // PHPUnit is always loaded in our own suite; this is the path of a suite without it.
        // @codeCoverageIgnoreStart
        throw new \AssertionError($message);
        // @codeCoverageIgnoreEnd
    }
}
