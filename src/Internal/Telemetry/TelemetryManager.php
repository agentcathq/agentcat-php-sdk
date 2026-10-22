<?php

declare(strict_types=1);

namespace AgentCat\Internal\Telemetry;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Delivery\ExporterSink;
use AgentCat\Internal\Delivery\NullExporterSink;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\ErrorText;
use AgentCat\Internal\Support\StrictErrors;
use AgentCat\Internal\Transport\HttpChannel;

/**
 * TypeScript `TelemetryManager`: one exporter per configured name; each event goes to all of them, and a failure of
 * one never stops the others or the AgentCat delivery. It runs once per API attempt (B03, D15).
 *
 * @internal
 */
final class TelemetryManager implements ExporterSink
{
    private const string IGNORED = 'WARN: AgentCat exporters were set up by an earlier track(); the exporters of this call are ignored';

    private bool $warned = false;

    /**
     * @param array<array-key, Exporter> $exporters keyed by the configured name
     * @param array<array-key, mixed>    $configs   what `track()` passed, to tell a later different set
     */
    public function __construct(
        private readonly array $exporters,
        private readonly array $configs = [],
    ) {}

    /**
     * Builds every exporter it can: an unknown entry or an invalid Sentry DSN logs one line and is skipped.
     *
     * @param array<array-key, mixed> $configs
     */
    public static function fromConfigs(array $configs, HttpChannel $http): self
    {
        $exporters = [];
        foreach ($configs as $name => $config) {
            try {
                $exporter = ExporterFactory::create($config, $http);
            } catch (\Throwable $e) {
                Log::write(sprintf('Failed to initialize exporter %s: %s', $name, ErrorText::of($e)));

                continue;
            }
            if ($exporter instanceof Exporter) {
                $exporters[$name] = $exporter;
                Log::write('Initialized telemetry exporter: ' . $name);
            }
        }

        return new self($exporters, $configs);
    }

    /**
     * First wins (TypeScript: process-global; here per queue, which is the process queue outside tests). Null
     * changes nothing; a later different set logs one WARN line; a sink that is not ours stays.
     *
     * @param array<array-key, mixed>|null $configs
     */
    public static function install(EventQueue $queue, ?array $configs): void
    {
        if ($configs === null) {
            return;
        }
        $sink = $queue->exporterSink();
        if ($sink instanceof NullExporterSink) {
            $queue->setExporterSink(self::fromConfigs($configs, $queue->channel()));
            Log::write(sprintf('Initialized telemetry with %d exporters', count($configs)));
        } elseif ($sink instanceof self && !$sink->warned && !self::same($configs, $sink->configs)) {
            $sink->warned = true; // once per process, only for a different set
            Log::write(self::IGNORED);
        }
    }

    #[\Override]
    public function export(Event $event): void
    {
        foreach ($this->exporters as $name => $exporter) {
            $reject = static function (string $message) use ($name): void {
                Log::write(sprintf('Telemetry export failed for %s: %s', $name, $message));
            };
            try {
                $exporter->export($event, $reject);
            } catch (\Throwable $e) {
                $reject(ExportErrors::message($e));
            }
        }
    }

    public function count(): int
    {
        return count($this->exporters);
    }

    /**
     * A set that cannot be serialized (a closure) or whose serialization warns counts as different, and nothing throws
     * or reaches the output (D32).
     *
     * @param array<array-key, mixed> $configs
     * @param array<array-key, mixed> $installed
     */
    private static function same(array $configs, array $installed): bool
    {
        try {
            return StrictErrors::run(static fn(): bool => serialize($configs) === serialize($installed));
        } catch (\Throwable) {
            return false;
        }
    }
}
