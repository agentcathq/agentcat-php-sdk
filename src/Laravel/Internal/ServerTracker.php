<?php

declare(strict_types=1);

namespace AgentCat\Laravel\Internal;

use AgentCat\AgentCat;
use AgentCat\Client;
use AgentCat\Internal\ClientSeams;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\ConfigOptions;
use AgentCat\Internal\Support\EnabledGate;
use AgentCat\Internal\Support\ErrorText;
use AgentCat\Options;
use AgentCat\Testing\Internal\FakeLog;
use Illuminate\Container\Container as GlobalContainer;
use Illuminate\Contracts\Container\Container;
use Laravel\Mcp\Server;

/**
 * Tracks each laravel/mcp server the container resolves (plan E1 header conflicts 10-12). Never throws: the
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
    public const array BRIDGE_KEYS = ['enabled', 'project_id', 'exclude', 'projects', 'log_channel'];

    private ?Client $client = null;

    private ?Options $options = null;

    public function track(Server $server, Container $app): void
    {
        try {
            $config = $app->make('config')->get('agentcat');
            $config = is_array($config) ? $config : [];
            if ($this->matches($server, $config['exclude'] ?? []) !== null) {
                return;
            }
            $this->adoptLogger($app, $config['log_channel'] ?? null);
            $client = AgentCat::client();
            // A fake records every server (header conflict 18); the shared client is never replaced (header conflict 11).
            $client = FakeLog::of($client) instanceof FakeLog ? $client : ($this->client ??= new Client(ClientSeams::of(EventQueue::global(), EnabledGate::of($config['enabled'] ?? null))));
            $this->options ??= ConfigOptions::options($config, self::BRIDGE_KEYS, $this->resolve(...));
            $client->track($server, $this->project($server, $config), $this->options);
        } catch (\Throwable $e) {
            Log::write('Warning: Failed to track server - ' . ErrorText::of($e));
        }
    }

    /**
     * A hook's class, resolved when the hook runs from the current container: under Octane, the sandbox of the
     * request, never a container of an earlier request (Review Focus 4).
     */
    private function resolve(string $class): mixed
    {
        return GlobalContainer::getInstance()->make($class);
    }

    /** @param array<array-key, mixed> $config */
    private function project(Server $server, array $config): ?string
    {
        $projects = is_array($config['projects'] ?? null) ? $config['projects'] : [];
        $class = $this->matches($server, array_keys($projects));
        // A null entry (env() of an unset variable) is the configured project: under config:cache the process does
        // not hold .env, so AGENTCAT_PROJECT_ID is read only when the configuration has no project either (D36).
        $project = $class !== null ? ($projects[$class] ?? null) : null;
        $project ??= $config['project_id'] ?? null;

        return is_string($project) ? $project : null;
    }

    /** The first listed class the server is an instance of; a class also matches its subclasses. */
    private function matches(Server $server, mixed $classes): ?string
    {
        foreach (is_array($classes) ? $classes : [] as $class) {
            if (is_string($class) && $server instanceof $class) {
                return $class;
            }
        }

        return null;
    }

    /**
     * D30: AgentCat's lines go to the configured log channel, else to the default one. The first adopted logger of the
     * process wins, and a fake's diversion keeps priority; after a fake, the next server routes the lines again. The
     * channel is resolved only while no logger is chosen: Laravel does not keep the emergency logger of a channel it
     * cannot create, and logs that failure each time it is asked.
     */
    private function adoptLogger(Container $app, mixed $channel): void
    {
        if (!Log::hasLogger()) {
            Log::adopt($app->make('log')->channel(is_string($channel) && $channel !== '' ? $channel : null));
        }
    }
}
