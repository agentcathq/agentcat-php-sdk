<?php

declare(strict_types=1);

namespace AgentCat\Internal\Telemetry\Sentry;

use AgentCat\Event;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\Values;
use AgentCat\Internal\Telemetry\Exporter;
use AgentCat\Internal\Telemetry\ExportErrors;
use AgentCat\Internal\Transport\HttpChannel;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\Internal\Transport\HttpResponse;

/**
 * TypeScript `SentryExporter.export()`: the log, then the transaction (with tracing), then the error event (failed
 * calls), one after the other. Each envelope is built before its `Sending` line. A thrown error or a network error
 * ends the sequence with `Sentry export error: <error>` (TS: one `catch` around the awaited steps); a non-2xx answer
 * logs its status and body and the sequence goes on. Never rejects.
 *
 * @internal
 */
final readonly class SentryExport implements Exporter
{
    public function __construct(
        private SentryPayload $payload,
        private HttpChannel $http,
    ) {
        Log::write('SentryExporter: Initialized with endpoint ' . $payload->endpoint);
    }

    #[\Override]
    public function export(Event $event, \Closure $reject): void
    {
        self::guarded(function () use ($event): void {
            $envelope = $this->payload->logEnvelope($event, $this->now());
            Log::write(sprintf('SentryExporter: Sending log for event %s to Sentry', $event->id));
            $this->send($envelope, 'log', $event->id, fn() => $this->transaction($event));
        });
    }

    private function transaction(Event $event): void
    {
        if (!$this->payload->enableTracing) {
            $this->error($event);

            return;
        }
        $transaction = $this->payload->transaction($event);
        $envelope = $this->payload->transactionEnvelope($transaction, $this->now());
        Log::write(sprintf('SentryExporter: Sending transaction %s to Sentry', $transaction->eventId));
        $this->send($envelope, 'transaction', $event->id, fn() => $this->error($event));
    }

    private function error(Event $event): void
    {
        if ($event->isError !== true) {
            return;
        }
        $error = $this->payload->errorEvent($event, $this->payload->enableTracing ? $this->payload->transaction($event) : null);
        $envelope = $this->payload->errorEnvelope($error, $this->now());
        Log::write(sprintf('SentryExporter: Sending error event %s to Sentry for Issue creation', $error['eventId']));
        $this->send($envelope, 'error', $event->id, null);
    }

    /** @param (\Closure(): void)|null $next the following step, after any answer */
    private function send(string $envelope, string $kind, string $id, ?\Closure $next): void
    {
        $this->http->post(new HttpRequest($this->payload->endpoint, $this->payload->headers(), $envelope), static function (HttpResponse $response) use ($kind, $id, $next): void {
            if ($response->error !== null) {
                Log::write('Sentry export error: ' . $response->error); // TS: the awaited fetch() rejects into the one catch

                return;
            }
            Log::write($response->ok()
                ? sprintf('Sentry %s export success - Event: %s', $kind, $id)
                : sprintf('Sentry %s export failed - Status: %d, Body: %s', $kind, $response->status, $response->body));
            if ($next instanceof \Closure) {
                self::guarded($next);
            }
        });
    }

    /** @param \Closure(): void $step */
    private static function guarded(\Closure $step): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            Log::write('Sentry export error: ' . ExportErrors::text($e));
        }
    }

    private function now(): string
    {
        return Values::isoDate(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }
}
