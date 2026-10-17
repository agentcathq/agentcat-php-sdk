<?php

declare(strict_types=1);

namespace AgentCat;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\Env;
use AgentCat\Internal\Support\ErrorText;
use AgentCat\Internal\Support\TestMode;
use AgentCat\McpSdk\McpSdkAdapter;
use Psr\Log\LoggerInterface;

/**
 * Tracks MCP servers and delivers their events to AgentCat.
 *
 * The static `AgentCat` class uses one shared client. Inject a `Client` instead when your application gets its
 * services from a dependency-injection container. Every client sends through the same process-wide event queue.
 */
final readonly class Client
{
    private const string PROJECT_ID_PATTERN = '/\Aproj_[0-9A-Za-z]{27}\z/';

    /** @var \WeakMap<object, TrackedServer> the tracked servers; an entry goes away with its server */
    private \WeakMap $tracked;

    private Publisher $publisher;

    private CallInterceptor $interceptor;

    /** @var \Closure(): bool */
    private \Closure $enabled;

    /**
     * Creates a client that sends through the process-wide event queue. Call it without arguments.
     *
     * @param EventQueue|null $queue internal: the queue for tests and `AgentCat::fake()`; default the process-wide queue
     * @param (\Closure(): bool)|null $enabled internal: whether `track()` instruments; default off in test environments
     *                                         unless `AGENTCAT_ENABLED` is `true`
     */
    public function __construct(?EventQueue $queue = null, ?\Closure $enabled = null)
    {
        $this->tracked = new \WeakMap();
        $this->publisher = new Publisher($queue ?? EventQueue::global());
        $this->interceptor = new CallInterceptor($this->publisher);
        $this->enabled = $enabled ?? TestMode::sdkEnabled(...);
    }

    /**
     * Adds AgentCat analytics to an MCP server. Call it before the server is built: for mcp/sdk, pass the
     * `Mcp\Server\Builder` before `build()`.
     *
     * It never throws. When the server cannot be tracked, it logs why and returns the server unchanged. Tracking
     * the same server again changes nothing. In test environments (PHPUnit, Pest, `APP_ENV` `testing` or `test`)
     * it does nothing unless `AGENTCAT_ENABLED` is `true`.
     *
     * @template T of object
     *
     * @param T $server the server to track
     * @param string|null $projectId your AgentCat project ID (`proj_…`); null reads `AGENTCAT_PROJECT_ID`; `''`
     *                               sends events to no AgentCat project (telemetry only)
     * @param Options|null $options the tracking options; null uses the defaults
     *
     * @return T the same server
     */
    public function track(object $server, ?string $projectId = null, ?Options $options = null): object
    {
        try {
            if (!($this->enabled)()) {
                return $server; // D31: no instrumentation in test environments unless fake() or AGENTCAT_ENABLED
            }
            $options ??= new Options();
            $projectId ??= Env::get('AGENTCAT_PROJECT_ID') ?? ''; // D36: '' means telemetry only
            if (!McpSdkAdapter::supports($server)) {
                $signals = McpSdkAdapter::fingerprint($server);
                Log::write(sprintf('AgentCat SDK Compatibility: unrecognized server shape | signals %s. %s', $signals === '' ? '(none)' : $signals, McpSdkAdapter::SUPPORT_MATRIX));

                throw new \UnexpectedValueException('AgentCat SDK compatibility error: server object does not match any supported MCP SDK shape. ' . McpSdkAdapter::SUPPORT_MATRIX);
            }
            $logger = McpSdkAdapter::logger($server);
            if ($logger instanceof LoggerInterface) {
                Log::adopt($logger); // D30, D38
            }
            McpSdkAdapter::inspect($server);
            if (($options->apiBaseUrl ?? '') !== '' || Env::get('AGENTCAT_API_URL') !== null || Env::get('MCPCAT_API_URL') !== null) {
                $this->publisher->queue()->configure($options->apiBaseUrl); // before the already-tracked check; the last call wins
            }
            $project = $projectId !== '' ? $projectId : '(telemetry-only)';
            Log::write(sprintf('AgentCat setup started | project %s | sdk mcp-sdk/builder | signals %s', $project, McpSdkAdapter::fingerprint($server)));
            if (isset($this->tracked[$server])) {
                Log::write('[SESSION DEBUG] track() - Server already being tracked, skipping initialization');

                return $server;
            }
            if ($projectId === '') {
                Log::write('Warning: No projectId provided and no exporters configured. Events will not be sent anywhere.');
            } elseif (preg_match(self::PROJECT_ID_PATTERN, $projectId) !== 1) {
                Log::write(sprintf('WARN: Project ID "%s" does not match proj_ followed by 27 letters or digits; the AgentCat API drops its events', $projectId));
            }
            $state = new TrackedServer($projectId, $options, McpSdkAdapter::serverInfo($server));
            McpSdkAdapter::install($server, $state, $this->publisher, $this->interceptor);
            $this->tracked[$server] = $state;
            Log::write(sprintf(
                'AgentCat setup complete | project %s | tracing=%s context=%s reportMissing=%s exporters=0',
                $project,
                $options->enableTracing ? 'true' : 'false',
                $options->enableToolCallContext ? 'true' : 'false',
                $options->enableReportMissing ? 'true' : 'false',
            ));

            return $server;
        } catch (\Throwable $e) {
            Log::write('Warning: Failed to track server - ' . ErrorText::of($e));

            return $server;
        }
    }

    /**
     * Sends the queued events now and waits for them. Rarely needed: AgentCat delivers on its own, between requests
     * and when the process ends.
     *
     * @param float|null $timeoutSeconds the longest wait in seconds; null waits until every event is sent
     *
     * @return bool true when every queued event was sent, false when the timeout ended first
     */
    public function flush(?float $timeoutSeconds = null): bool
    {
        return $this->publisher->queue()->flush($timeoutSeconds);
    }

    /**
     * Stops accepting events, then sends the queued ones for at most 5 seconds. Events after this are dropped.
     * Rarely needed: AgentCat does this on its own when the process ends.
     */
    public function shutdown(): void
    {
        $this->publisher->queue()->shutdown();
    }
}
