<?php

declare(strict_types=1);

namespace AgentCat\Internal\ServerInfo;

use AgentCat\Internal\Delivery\DeferredWork;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\ErrorText;
use AgentCat\Internal\Transport\ApiBaseUrl;

/**
 * One due heartbeat, from the check that started it until the queue runs it: the tracked server and the lists of the
 * request. The run reads the lists, redacts their text with the customer's `redactSensitiveInformation` function, and
 * queues the request (`Sender`). Whatever fails, it sends nothing and logs one line; no error leaves it, and no PHP
 * warning or notice of the customer's list handlers or of the function reaches PHP's error output.
 *
 * @internal
 */
final readonly class PendingHeartbeat implements DeferredWork
{
    /** Reading the lists and redacting them stop after this many seconds. */
    public const float BUILD_SECONDS = 30.0;

    private const string SKIPPED = 'Skipped the server info heartbeat: ';

    /** @param \Closure(): float $clock seconds on a monotonic clock */
    public function __construct(
        private TrackedServer $server,
        private ServerInfoSource $source,
        private \Closure $clock,
    ) {}

    #[\Override]
    public function run(float $secondsLeft): void
    {
        set_error_handler(static fn(): bool => true);

        try {
            $this->send($secondsLeft);
        } catch (Skipped $skipped) {
            Log::write(self::SKIPPED . $skipped->getMessage());
        } catch (\Throwable $e) {
            Log::write(self::SKIPPED . ErrorText::of($e));
        }
        restore_error_handler(); // every throwable is caught above, and the log never throws
    }

    #[\Override]
    public function drop(string $reason): void
    {
        Log::write(self::SKIPPED . $reason);
    }

    /** @throws \Throwable */
    private function send(float $secondsLeft): void
    {
        [$name, $version] = $this->server->serverInfo(); // read as an event reads them
        if ($name === null || $name === '') {
            throw new Skipped('the server name is unknown');
        }
        $clock = $this->clock;
        $started = $clock();
        $check = static function () use ($clock, $started, $secondsLeft): void {
            $taken = $clock() - $started;
            if ($taken >= self::BUILD_SECONDS) {
                throw new Skipped(sprintf('reading and redacting the lists took more than %s s', self::BUILD_SECONDS));
            }
            if ($taken >= $secondsLeft) {
                throw new Skipped('the delivery time ended before the lists were read');
            }
        };
        $body = BodyBuilder::build($this->server->projectId, $name, $version, $this->source, $this->server->injectionConfig(), $clock, $check);
        $redactor = $this->server->options->redactSensitiveInformation;
        if ($redactor instanceof \Closure) {
            try {
                $body = TextRedaction::apply($body, $redactor, $check);
            } catch (Skipped $skipped) {
                throw $skipped;
            } catch (\Throwable $e) {
                throw Skipped::after('redactSensitiveInformation failed - ' . ErrorText::of($e), $e);
            }
        }
        $check();
        $queue = $this->server->publisher()->queue();
        Sender::send($queue->channel(), ApiBaseUrl::serverInfoEndpoint($queue->baseUrl()), $body);
    }
}
