<?php

declare(strict_types=1);

namespace AgentCat\Internal\ServerInfo;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Transport\HttpChannel;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\Internal\Transport\HttpResponse;

/**
 * Sends a heartbeat: one `POST` on the queue's side channel, with the headers of an event and no `Authorization`
 * header. It is a background request, so a `flush()` of the customer's never waits for it; the window that ran the
 * heartbeat and the shutdown delivery do. A body that the API finds too large (413) goes once more without the tool
 * schemas; no other answer sends anything again. The answer is one log line, which never holds the text of a list.
 *
 * @internal
 */
final class Sender
{
    public const float TIMEOUT_SECONDS = 10.0;

    private const int TOO_LARGE = 413;

    /**
     * Queues the request; the channel's passes send it. Nothing blocks.
     *
     * @param (\Closure(): void)|null $settled runs once, when the last answer for the heartbeat is in or it failed
     *
     * @throws \JsonException for a body JSON cannot carry: nothing is queued
     */
    public static function send(HttpChannel $channel, string $url, ServerInfoBody $body, ?\Closure $settled = null): void
    {
        self::post($channel, $url, $body, true, $settled ?? static function (): void {});
    }

    /**
     * @param \Closure(): void $settled
     *
     * @throws \JsonException
     */
    private static function post(HttpChannel $channel, string $url, ServerInfoBody $body, bool $full, \Closure $settled): void
    {
        $json = $body->encode();
        $request = new HttpRequest($url, ['Content-Type' => 'application/json', 'Accept' => 'application/json'], $json, true, totalTimeoutSeconds: self::TIMEOUT_SECONDS);
        $channel->post($request, static function (HttpResponse $response) use ($channel, $url, $body, $full, $json, $settled): void {
            try {
                if ($full && $response->status === self::TOO_LARGE) {
                    Log::write('Server info heartbeat was too large (HTTP 413); sending it once more without the tool schemas');
                    self::post($channel, $url, $body->withoutSchemas(), false, $settled);

                    return;
                }
                if ($response->ok()) {
                    Log::write(sprintf('Sent server info heartbeat | %d tools | %d bytes%s', $body->toolCount(), strlen($json), self::failedLists($body)));
                } else {
                    Log::write('Server info heartbeat failed: ' . ($response->error ?? 'HTTP ' . $response->status));
                }
            } catch (\Throwable $e) {
                Log::write('Server info heartbeat failed: ' . $e::class);
            }
            $settled();
        });
    }

    private static function failedLists(ServerInfoBody $body): string
    {
        $failed = [];
        foreach ($body->failedLists as $list => $reason) {
            $failed[] = sprintf('%s (%s)', $list, $reason);
        }

        return $failed === [] ? '' : ' | without ' . implode(', ', $failed);
    }
}
