<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use AgentCat\Internal\Json\Json;
use Illuminate\Container\Container;
use Laravel\Mcp\Server;
use PHPUnit\Framework\Assert;

/**
 * Drives one laravel/mcp server instance the way `artisan mcp:start` does: one server, built by the container, answers
 * every message, one line each, on a `LineTransport`.
 */
final readonly class LaravelStdioHarness
{
    private LineTransport $transport;

    /**
     * @param class-string<Server> $serverClass
     * @param (\Closure(Server): void)|null $track runs on the server before `start()`
     */
    public function __construct(string $serverClass, ?\Closure $track = null)
    {
        $this->transport = new LineTransport();
        $server = Container::getInstance()->make($serverClass, ['transport' => $this->transport]);
        Assert::assertInstanceOf(Server::class, $server);
        if ($track instanceof \Closure) {
            $track($server);
        }
        $server->start(); // the transport keeps the server's handler, so the server lives as long as this harness
    }

    /**
     * Hands one message to the server.
     *
     * @param array<string, mixed> $message
     *
     * @return ?\stdClass the last line the server wrote for it, decoded; null when it wrote none
     */
    public function send(array $message): ?\stdClass
    {
        return $this->transport->deliver($message);
    }

    /**
     * Every line the server wrote, decoded, in order: a streamed answer writes several.
     *
     * @return list<\stdClass>
     */
    public function lines(): array
    {
        return array_map(static function (string $line): \stdClass {
            $message = Json::decode($line);
            Assert::assertInstanceOf(\stdClass::class, $message);

            return $message;
        }, $this->transport->sent);
    }
}
