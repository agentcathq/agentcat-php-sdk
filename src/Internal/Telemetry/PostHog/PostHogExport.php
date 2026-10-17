<?php

declare(strict_types=1);

namespace AgentCat\Internal\Telemetry\PostHog;

use AgentCat\Event;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Telemetry\Exporter;
use AgentCat\Internal\Telemetry\ExportErrors;
use AgentCat\Internal\Transport\HttpChannel;
use AgentCat\Internal\Transport\HttpResponse;

/**
 * TypeScript `PostHogExporter.export()`: one batch POST. Every failure logs `PostHog export error: <error>` or the
 * status and body. Never rejects.
 *
 * @internal
 */
final readonly class PostHogExport implements Exporter
{
    public function __construct(
        private PostHogPayload $payload,
        private HttpChannel $http,
    ) {
        Log::write('PostHogExporter: Initialized with endpoint ' . $payload->batchUrl);
    }

    #[\Override]
    public function export(Event $event, \Closure $reject): void
    {
        try {
            $batch = $this->payload->batch($event);
            Log::write(sprintf('PostHogExporter: Sending %d event(s) for %s', count($batch), $event->id));
            $id = $event->id;
            $this->http->post($this->payload->request($batch), static function (HttpResponse $response) use ($id): void {
                if ($response->error !== null) {
                    Log::write('PostHog export error: ' . $response->error);
                } elseif (!$response->ok()) {
                    Log::write(sprintf('PostHog export failed - Status: %d, Body: %s', $response->status, $response->body));
                } else {
                    Log::write('PostHog export success - Event: ' . $id);
                }
            });
        } catch (\Throwable $e) {
            Log::write('PostHog export error: ' . ExportErrors::text($e));
        }
    }
}
