<?php

declare(strict_types=1);

namespace AgentCat\Internal\Telemetry\Datadog;

use AgentCat\Event;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Telemetry\Exporter;
use AgentCat\Internal\Transport\HttpChannel;
use AgentCat\Internal\Transport\HttpResponse;

/**
 * TypeScript `DatadogExporter.export()`: the log and the metrics POSTs side by side, each logging its own outcome. A
 * payload that cannot be built (spec conflict 10) throws, which the manager logs as the rejection.
 *
 * @internal
 */
final readonly class DatadogExport implements Exporter
{
    public function __construct(
        private DatadogPayload $payload,
        private HttpChannel $http,
    ) {}

    #[\Override]
    public function export(Event $event, \Closure $reject): void
    {
        Log::write('DatadogExporter: Sending event immediately to Datadog');
        $requests = $this->payload->requests($event); // outside any try in TS: a failure rejects, the manager logs it
        Log::write('DatadogExporter: Metrics URL: ' . $this->payload->metricsUrl);
        Log::write(sprintf('DatadogExporter: Sending %d metric series', $requests['series']));
        $this->http->post($requests['logs'], $this->outcome('logs'));
        $this->http->post($requests['metrics'], $this->outcome('metrics'));
    }

    /** @return \Closure(HttpResponse): void */
    private function outcome(string $kind): \Closure
    {
        return static function (HttpResponse $response) use ($kind): void {
            if ($response->error !== null) {
                Log::write(sprintf('Datadog %s network error: %s', $kind, $response->error));

                return;
            }
            Log::write(sprintf('Datadog %s %s - Status: %d', $kind, $response->ok() ? 'success' : 'failed', $response->status));
        };
    }
}
