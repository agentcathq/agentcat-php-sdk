<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use AgentCat\Internal\Json\Json;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * A laravel/mcp transport for tests: it keeps the handler that `Server::start()` registers and every line the server
 * sends. `deliver()` hands one message to the server, like a line on stdin.
 */
final class LineTransport implements Transport
{
    /** @var list<string> every line the server sent, in order */
    public array $sent = [];

    /** @var (\Closure(string): mixed)|null the server's handler of one raw message */
    private ?\Closure $handler = null;

    // @phpstan-ignore missingType.callable (laravel/mcp's Transport takes any Closure: a signature breaks contravariance)
    #[\Override]
    public function onReceive(\Closure $handler): void
    {
        $this->handler = static fn(string $message): mixed => $handler($message);
    }

    /** Does nothing: the test delivers each message with `deliver()`. */
    #[\Override]
    public function run(): void {}

    #[\Override]
    public function send(string $message): void
    {
        $this->sent[] = $message;
    }

    /** Runs the stream at once, like laravel/mcp's stdio transport. */
    // @phpstan-ignore missingType.callable (see onReceive())
    #[\Override]
    public function stream(\Closure $stream): void
    {
        $stream();
    }

    /**
     * Hands the message, JSON-encoded, to the server.
     *
     * @param array<string, mixed> $message
     *
     * @return ?\stdClass the last line the server sent for it, decoded; null when it sent none
     */
    public function deliver(array $message): ?\stdClass
    {
        $handler = $this->handler ?? throw new \LogicException('Start the server before delivering a message');
        $before = count($this->sent);

        $handler(json_encode($message, JSON_THROW_ON_ERROR));

        $lines = array_slice($this->sent, $before);
        $line = array_pop($lines);
        if ($line === null) {
            return null;
        }
        $answer = Json::decode($line);

        return $answer instanceof \stdClass ? $answer : throw new \UnexpectedValueException('Not a JSON object: ' . $line);
    }
}
