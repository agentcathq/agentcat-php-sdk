<?php

declare(strict_types=1);

namespace AgentCat\Laravel\Internal;

use AgentCat\AgentCat;
use AgentCat\Client;
use AgentCat\Internal\ClientSeams;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\BridgeKeys;
use AgentCat\Internal\Support\ConfigOptions;
use AgentCat\Internal\Support\EnabledGate;
use AgentCat\Internal\Support\ErrorText;
use AgentCat\Options;
use AgentCat\Testing\Internal\FakeLog;
use Illuminate\Container\Container as GlobalContainer;
use Illuminate\Contracts\Container\Container;
use Laravel\Mcp\Server;

/**
 * Tracks each laravel/mcp server the container resolves. Never throws: the
 * customer's server must come out of the container whatever AgentCat does.
 *
 * One tracker serves the application (an Octane worker too): it builds its client and the options from
 * config/agentcat.php at the first server it tracks, so each bad value logs once, and it reads `exclude` and
 * `projects` for every server.
 *
 * @internal
 */
final class ServerTracker
{
    /** @var list<string> The keys of config/agentcat.php that the bridge reads itself; the others are options. */
    public const array BRIDGE_KEYS = ['enabled', 'project_id', 'exclude', 'projects', 'log_channel', 'diagnostics_endpoint', 'diagnostics_token'];

    /** config/agentcat.php's `exclude`: the default of a value of a wrong type too. */
    public const array DEFAULT_EXCLUDE = ['Laravel\Boost\Mcp\Boost'];

    private ?Client $client = null;

    private ?Options $options = null;

    /** @var array<string, true> the lines of bad bridge values logged so far: each once per process */
    private array $warned = [];

    public function track(Server $server, Container $app): void
    {
        try {
            $config = $app->make('config')->get('agentcat');
            $config = is_array($config) ? $config : [];
            $bad = new \ArrayObject();
            $keys = new BridgeKeys($config, static function (string $line) use ($bad): void {
                $bad->append($line);
            });
            if ($this->matches($server, $keys->strings('exclude', self::DEFAULT_EXCLUDE)) !== null) {
                return; // an excluded server touches neither the log nor diagnostics
            }
            $this->adoptLogger($app, $keys->string('log_channel'));
            $project = $this->project($server, $keys);
            $keys->string('diagnostics_endpoint'); // read at boot (BridgeConfig); here for the line of a bad value
            $keys->string('diagnostics_token');
            $this->warnOnce($bad->getArrayCopy());
            $client = AgentCat::client();
            // A fake records every server whatever `agentcat.enabled` says; the shared client is never replaced.
            $client = FakeLog::of($client) instanceof FakeLog ? $client : ($this->client ??= new Client(ClientSeams::of(EventQueue::global(), EnabledGate::of($config['enabled'] ?? null))));
            $this->options ??= ConfigOptions::options($config, self::BRIDGE_KEYS, $this->resolve(...));
            $client->track($server, $project, $this->options);
            if (TrackedServers::get($server) instanceof TrackedServer) {
                AnswerLength::mark($server); // D59; a server that AgentCat did not track (off, or failed) keeps its answer
            }
        } catch (\Throwable $e) {
            TrackedServers::markIncomplete(); // as Client::track(): publishCustomEvent() for the server then logs (D32)
            Log::write('Warning: Failed to track server - ' . ErrorText::of($e));
        }
    }

    /**
     * A hook's class, resolved when the hook runs from the current container: under Octane, the sandbox of the
     * request, never a container of an earlier request.
     */
    private function resolve(string $class): mixed
    {
        return GlobalContainer::getInstance()->make($class);
    }

    private function project(Server $server, BridgeKeys $keys): ?string
    {
        $projects = $keys->projects();
        $class = $this->matches($server, array_keys($projects));
        // A null entry (env() of an unset variable) is the configured project: under config:cache the process does
        // not hold .env, so AGENTCAT_PROJECT_ID is read only when the configuration has no project either (D36).
        $project = $class !== null ? ($projects[$class] ?? null) : null;

        return $project ?? $keys->string('project_id');
    }

    /**
     * The first listed class the server is an instance of; a class also matches its subclasses.
     *
     * @param list<array-key> $classes
     */
    private function matches(Server $server, array $classes): ?string
    {
        foreach ($classes as $class) {
            if (is_string($class) && $server instanceof $class) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Each line once per process: laravel/mcp builds a server per HTTP request, and the configuration does not change.
     *
     * @param array<string> $lines
     */
    private function warnOnce(array $lines): void
    {
        foreach ($lines as $line) {
            if (!isset($this->warned[$line])) {
                $this->warned[$line] = true;
                Log::write($line);
            }
        }
    }

    /**
     * D30, D60: AgentCat's lines go to the configured log channel, else to their own file, a `single` channel at
     * `storage/logs/agentcat.log` built through Laravel's logging (TypeScript writes `~/mcpcat.log`): laravel/mcp builds
     * a server per HTTP request, so the setup lines repeat per request, and they stay out of the application's log
     * and its error trackers. The first adopted logger of the process wins, and a fake's diversion keeps priority;
     * after a fake, the next server routes the lines again. The channel is resolved only while no logger is chosen:
     * Laravel does not keep the emergency logger of a channel it cannot create, and logs that failure each time it is
     * asked.
     */
    private function adoptLogger(Container $app, ?string $channel): void
    {
        if (Log::hasLogger()) {
            return;
        }
        $log = $app->make('log');
        Log::adopt($channel !== null && $channel !== ''
            ? $log->channel($channel)
            : $log->build(['driver' => 'single', 'name' => 'agentcat', 'path' => storage_path('logs/agentcat.log')]));
    }
}
