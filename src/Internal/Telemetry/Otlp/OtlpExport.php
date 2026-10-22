<?php

declare(strict_types=1);

namespace AgentCat\Internal\Telemetry\Otlp;

use AgentCat\Event;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Telemetry\Exporter;
use AgentCat\Internal\Telemetry\ExportErrors;
use AgentCat\Internal\Transport\HttpChannel;
use AgentCat\Internal\Transport\HttpResponse;

/**
 * TypeScript `OTLPExporter.export()`: one POST; every failure rejects with `OTLP export error: <error>`.
 *
 * @internal
 */
final readonly class OtlpExport implements Exporter
{
    public function __construct(
        private OtlpPayload $payload,
        private HttpChannel $http,
    ) {}

    #[\Override]
    public function export(Event $event, \Closure $reject): void
    {
        try {
            $request = $this->payload->request($event);
        } catch (\Throwable $e) {
            $reject('OTLP export error: ' . ExportErrors::text($e));

            return;
        }
        $id = $event->id;
        $this->http->post($request, static function (HttpResponse $response) use ($reject, $id): void {
            if ($response->error !== null) {
                $reject('OTLP export error: ' . $response->error);
            } elseif (!$response->ok()) {
                $reject(sprintf('OTLP export error: Error: OTLP export failed: %d %s', $response->status, $response->reason));
            } else {
                Log::write('Successfully exported event to OTLP: ' . $id);
            }
        });
    }
}
