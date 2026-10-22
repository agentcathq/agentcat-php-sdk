<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

use Mcp\Event\ResponseEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

/** A customer's PSR-14 dispatcher: records the result of every `ResponseEvent`, as a listener sees it. */
final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<array{method: string, result: mixed}> */
    public array $responses = [];

    #[\Override]
    public function dispatch(object $event): object
    {
        if ($event instanceof ResponseEvent) {
            $this->responses[] = ['method' => $event->getMethod(), 'result' => $event->getResponse()->result];
        }

        return $event;
    }

    /**
     * The results listeners saw for this method, in order.
     *
     * @return list<mixed>
     */
    public function results(string $method): array
    {
        return array_column(array_filter(
            $this->responses,
            static fn(array $response): bool => $response['method'] === $method,
        ), 'result');
    }
}
