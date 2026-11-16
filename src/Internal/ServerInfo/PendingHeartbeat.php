<?php

declare(strict_types=1);

namespace AgentCat\Internal\ServerInfo;

use AgentCat\Internal\Delivery\DeferredWork;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Transport\ApiBaseUrl;

/**
 * One due heartbeat, from the check that started it until the queue runs it, in the window after the response of the
 * same request: the tracked server and the lists of the request. The run reads the lists, redacts their text with the
 * customer's `redactSensitiveInformation` function, and queues the request (`Sender`).
 *
 * Whatever fails, it sends nothing and logs one line, and nothing of the customer's code leaves it: no error, no PHP
 * warning or notice (an error handler takes them), no output (an output buffer takes what a handler prints, and it is
 * discarded). A log line names the class of what was thrown, never its message, because a message can hold what the
 * redaction function is there to hide.
 *
 * @internal
 */
final class PendingHeartbeat implements DeferredWork
{
    /** Reading the lists and redacting them stop after this many seconds. */
    public const float BUILD_SECONDS = 30.0;

    private const string SKIPPED = 'Skipped the server info heartbeat: ';

    /** True from the moment the request is queued until the API's last answer for it is in. */
    private bool $onItsWay = false;

    /** @param \Closure(): float $clock seconds on a monotonic clock */
    public function __construct(
        private readonly TrackedServer $server,
        private readonly ServerInfoSource $source,
        private readonly \Closure $clock,
    ) {}

    #[\Override]
    public function run(float $secondsLeft): void
    {
        set_error_handler(static fn(): bool => true);
        $level = ob_get_level();
        $buffered = ob_start();

        try {
            $body = $this->build($secondsLeft);
        } catch (Skipped $skipped) {
            $body = null;
            Log::write(self::SKIPPED . $skipped->getMessage());
        } catch (\Throwable $e) {
            $body = null;
            Log::write(self::SKIPPED . $e::class);
        }
        while ($buffered && ob_get_level() > $level) {
            ob_end_clean(); // what the customer's code printed, and every buffer it left open
        }
        restore_error_handler(); // every throwable is caught above, and the log never throws
        if ($body instanceof ServerInfoBody) {
            $this->send($body);
        }
    }

    #[\Override]
    public function unfinished(): bool
    {
        return $this->onItsWay;
    }

    #[\Override]
    public function drop(string $reason): void
    {
        Log::write(self::SKIPPED . $reason);
    }

    /**
     * The body, read and redacted: every call into the customer's code is here.
     *
     * @throws \Throwable
     */
    private function build(float $secondsLeft): ServerInfoBody
    {
        [$name, $version] = $this->source->server();
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
                throw Skipped::after('redactSensitiveInformation failed - ' . $e::class, $e);
            }
        }
        $check();

        return $body;
    }

    private function send(ServerInfoBody $body): void
    {
        try {
            $queue = $this->server->publisher()->queue();
            $this->onItsWay = true;
            Sender::send($queue->channel(), ApiBaseUrl::serverInfoEndpoint($queue->baseUrl()), $body, function (): void {
                $this->onItsWay = false;
            });
        } catch (\Throwable $e) {
            $this->onItsWay = false;
            Log::write(self::SKIPPED . $e::class);
        }
    }
}
