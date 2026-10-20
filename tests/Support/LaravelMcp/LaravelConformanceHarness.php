<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use AgentCat\Client;
use AgentCat\Internal\ClientSeams;
use AgentCat\LaravelMcp\Internal\MethodKey;
use AgentCat\Options;
use AgentCat\Tests\Integration\Conformance\ConformanceHarness;
use AgentCat\Tests\Support\ReusedId;
use AgentCat\Tests\Support\ToolCalls;
use Illuminate\Container\Container;
use Laravel\Mcp\Server;
use PHPUnit\Framework\Assert;

/**
 * The laravel/mcp leg of the adapter conformance suite: `ConformanceServer`, tracked into a `LaravelTrackingFixture`.
 * One instance answers every request after the legacy handshake as `harness-client` 1.0.0, as with `artisan mcp:start`;
 * a fresh harness builds a new one for each HTTP request, as `Mcp::web()` does. A call without `$meta` has no `_meta`:
 * laravel/mcp's everyday (legacy) call.
 */
final class LaravelConformanceHarness implements ConformanceHarness
{
    private int $nextId = 1;

    private function __construct(public readonly LaravelTrackingFixture $fixture, private readonly LaravelHarness|LaravelStdioHarness $server)
    {
        if ($server instanceof LaravelStdioHarness) {
            // A stdio client starts with the legacy handshake, like the mcp/sdk leg: the server keeps its client.
            $this->request('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'harness-client', 'version' => '1.0.0']]);
            $server->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        }
    }

    /** The conformance server, tracked with `$options`; a new instance per request when `$fresh`. */
    public static function of(Options $options = new Options(), bool $fresh = false): self
    {
        $fixture = new LaravelTrackingFixture();
        $track = $fixture->tracker($options);

        return new self($fixture, $fresh ? new LaravelHarness(ConformanceServer::class, $track) : new LaravelStdioHarness(ConformanceServer::class, $track));
    }

    /** The conformance server without AgentCat: the library's own answers. */
    public static function untracked(): self
    {
        return new self(new LaravelTrackingFixture(), new LaravelStdioHarness(ConformanceServer::class));
    }

    /** `FullPageServer`: exactly one default page of tools. */
    public static function fullPage(): self
    {
        $fixture = new LaravelTrackingFixture();

        return new self($fixture, new LaravelStdioHarness(FullPageServer::class, $fixture->tracker()));
    }

    /** `PagedOwnServer`: a full page, then `own` alone on page 2. */
    public static function paged(): self
    {
        $fixture = new LaravelTrackingFixture();

        return new self($fixture, new LaravelStdioHarness(PagedOwnServer::class, $fixture->tracker()));
    }

    /**
     * The conformance server, tracked by `Client::track()` after an earlier one, which the client had tracked with
     * `enableTracing: false`, was freed. Asserts that the new server has the old one's object ID, so the same
     * container keys, and that its binding replaced the old one's (plan E1 header conflict 3).
     */
    public static function reusedObjectId(): self
    {
        $fixture = new LaravelTrackingFixture();
        $client = new Client(ClientSeams::of($fixture->queue, static fn(): bool => true, false));
        $track = static function (Server $server) use ($client): void {
            $client->track($server, LaravelTrackingFixture::PROJECT);
        };
        $old = new ConformanceServer(new LineTransport());
        $client->track($old, LaravelTrackingFixture::PROJECT, new Options(enableTracing: false));
        $key = MethodKey::for($old, 'tools/call');
        $binding = Container::getInstance()->make($key);
        $id = spl_object_id($old);
        unset($old);
        $held = ReusedId::hold($id);

        $stdio = new LaravelStdioHarness(static fn(LineTransport $transport): Server => $held->reuse(static fn(): ConformanceServer => new ConformanceServer($transport)), $track);

        Assert::assertNotSame($binding, Container::getInstance()->make($key), 'the new server\'s binding replaced the old one\'s');

        return new self($fixture, $stdio);
    }

    #[\Override]
    public function listTools(): \stdClass
    {
        $result = $this->request('tools/list', [])->result ?? null;
        Assert::assertInstanceOf(\stdClass::class, $result);

        return $result;
    }

    #[\Override]
    public function callTool(string $name, array $arguments, array $meta = []): \stdClass
    {
        $params = ['name' => $name, 'arguments' => (object) $arguments];
        if ($meta !== []) {
            $params['_meta'] = LaravelHarness::meta($meta, null);
        }

        return $this->request('tools/call', $params);
    }

    #[\Override]
    public function events(): array
    {
        return $this->fixture->events();
    }

    #[\Override]
    public function received(string $tool): array
    {
        return ToolCalls::of($tool);
    }

    #[\Override]
    public function lines(): array
    {
        return $this->fixture->lines();
    }

    /**
     * One JSON-RPC request; the whole answer.
     *
     * @param array<string, mixed> $params
     */
    private function request(string $method, array $params): \stdClass
    {
        if ($this->server instanceof LaravelHarness) {
            return $this->server->request($method, $params);
        }
        $answer = $this->server->send(['jsonrpc' => '2.0', 'id' => $this->nextId++, 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params]);
        Assert::assertInstanceOf(\stdClass::class, $answer, 'the server answered');

        return $answer;
    }
}
