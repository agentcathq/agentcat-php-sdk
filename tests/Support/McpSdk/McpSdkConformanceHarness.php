<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

use AgentCat\Client;
use AgentCat\Internal\ClientSeams;
use AgentCat\Options;
use AgentCat\Tests\Integration\Conformance\ConformanceHarness;
use AgentCat\Tests\Support\ReusedId;
use Mcp\Server;
use Mcp\Server\Builder;
use PHPUnit\Framework\Assert;

/**
 * The mcp/sdk leg of the adapter conformance suite: `conformance-server` 2.0.0 with the tools `echo`, `structured`,
 * `refuses`, `boom` and `own`, tracked into a `TrackingFixture`. One server answers every request in the session of
 * its handshake as `harness-client` 1.0.0. A fresh harness builds, tracks and builds a new server for each request
 * and sends a 2026-07-28 request, because a handshake session does not survive a new server.
 */
final class McpSdkConformanceHarness implements ConformanceHarness
{
    public readonly TrackingFixture $fixture;

    /** @var list<array<string, RecordingTool>> the tools of each Builder, by name, in the order of the builds */
    private array $tools = [];

    /** The one server; null when fresh. */
    private ?HttpHarness $server = null;

    /** @param Options|null $options the tracking options; null tracks nothing */
    private function __construct(private readonly ?Options $options)
    {
        $this->fixture = new TrackingFixture();
    }

    /** The conformance server, tracked with `$options`; a new instance per request when `$fresh`. */
    public static function of(Options $options = new Options(), bool $fresh = false): self
    {
        $harness = new self($options);
        if (!$fresh) {
            $harness->server = $harness->fixture->harness($harness->tracked($harness->conformanceTools(self::builder())));
        }

        return $harness;
    }

    /** The conformance server without AgentCat: the library's own answers. */
    public static function untracked(): self
    {
        $harness = new self(null);
        $harness->server = $harness->fixture->harness($harness->conformanceTools(self::builder()));

        return $harness;
    }

    /** `echo`, then `t01` to `t14`, with a page size of 15: laravel/mcp's default page, one full page here too. */
    public static function fullPage(): self
    {
        $harness = new self(new Options());
        $builder = self::builder()->setPaginationLimit(15);
        $tools = ['echo' => Tools::echo($builder)];
        foreach (range(1, 14) as $i) {
            $tools[sprintf('t%02d', $i)] = Tools::named($builder, sprintf('t%02d', $i));
        }
        $harness->tools[] = $tools;
        $harness->server = $harness->fixture->harness($harness->tracked($builder));

        return $harness;
    }

    /**
     * The conformance server, tracked by `Client::track()` after an earlier one, which the client had tracked with
     * `enableTracing: false` and built, was freed. Asserts that the new Builder has the old one's object ID.
     */
    public static function reusedObjectId(): self
    {
        $harness = new self(null);
        $client = new Client(ClientSeams::of($harness->fixture->queue, static fn(): bool => true, false));
        $old = $harness->conformanceTools(self::builder());
        $client->track($old, TrackingFixture::PROJECT, new Options(enableTracing: false));
        $old->build();
        $id = spl_object_id($old);
        $gone = \WeakReference::create($old);
        $harness->tools = []; // received() reads the new server's tools only
        unset($old);
        gc_collect_cycles(); // AgentCat's handlers and the Builder hold each other
        Assert::assertNull($gone->get(), 'the old Builder is freed');
        $held = ReusedId::hold($id);

        $builder = $held->reuse(self::builder(...));

        $client->track($harness->conformanceTools($builder), TrackingFixture::PROJECT);
        $harness->server = $harness->fixture->harness($builder);

        return $harness;
    }

    #[\Override]
    public function listTools(): \stdClass
    {
        return $this->server instanceof HttpHarness ? $this->server->listTools() : HttpHarness::result($this->fresh()->modern('tools/list', []));
    }

    #[\Override]
    public function callTool(string $name, array $arguments, array $meta = []): \stdClass
    {
        $server = $this->server ?? $this->fresh();
        if ($meta !== []) {
            // modern() adds the protocol version and the client capabilities; the client info is in $meta, if anywhere.
            return $server->modern('tools/call', ['name' => $name, 'arguments' => (object) $arguments, '_meta' => $meta], $name, null);
        }

        return $this->server instanceof HttpHarness ? $server->callTool($name, $arguments) : $server->callToolModern($name, $arguments);
    }

    #[\Override]
    public function events(): array
    {
        return $this->fixture->events();
    }

    #[\Override]
    public function received(string $tool): array
    {
        return array_merge(...array_map(static fn(array $tools): array => isset($tools[$tool]) ? $tools[$tool]->calls : [], $this->tools));
    }

    #[\Override]
    public function lines(): array
    {
        return $this->fixture->lines();
    }

    /** A Builder of `conformance-server` 2.0.0, without tools. */
    private static function builder(): Builder
    {
        return Server::builder()->setServerInfo('conformance-server', '2.0.0');
    }

    /** Adds the five conformance tools, which `received()` reads. */
    private function conformanceTools(Builder $builder): Builder
    {
        $this->tools[] = [
            'echo' => Tools::echo($builder),
            'structured' => Tools::structured($builder),
            'refuses' => Tools::refuses($builder),
            'boom' => Tools::boom($builder),
            'own' => Tools::own($builder),
        ];

        return $builder;
    }

    /** Tracks the Builder into the fixture with the harness's options, unless the harness tracks nothing. */
    private function tracked(Builder $builder): Builder
    {
        if ($this->options instanceof Options) {
            $this->fixture->track($builder, $this->options);
        }

        return $builder;
    }

    /** A new server for one request: a new Builder, tracked, then built; no handshake. */
    private function fresh(): HttpHarness
    {
        return new HttpHarness($this->tracked($this->conformanceTools(self::builder()))->build());
    }
}
