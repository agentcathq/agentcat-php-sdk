<?php

declare(strict_types=1);

namespace AgentCat\Symfony\Internal;

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
use Mcp\Server\Builder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tracks each symfony/mcp-bundle server when the container builds its Builder (plan E2 header conflicts 2, 5, 6, 14).
 * Never throws: the customer's server must come out of the container whatever AgentCat does.
 *
 * One tracker serves the container (a worker's too). At the first server, it reads the configuration, whose environment
 * variables resolve then, so each bad value logs once, at the first server it tracks. A server that `exclude_servers`
 * names through an environment variable is left as it is: the pass removes only the servers named in the configuration
 * itself. A configuration that cannot be read (a variable without a default that is not set) logs one line for each
 * server, which then answers untracked.
 *
 * @internal
 */
final class ServerTracker
{
    /** @var list<string> The keys of the agentcat configuration that the bundle reads itself; the others are options. */
    public const array BRIDGE_KEYS = ['enabled', 'project_id', 'exclude_servers', 'projects', 'logger', 'diagnostics_endpoint', 'diagnostics_token'];

    private const string NO_LOGGER = 'WARN: AgentCat option "agentcat.logger" must be the ID of a PSR-3 logger service, got "%s"; the default is used';

    private const string LITERAL_ID = 'WARN: AgentCat option "agentcat.%s" must be a literal service ID, not an environment variable; the default is used';

    private ?Client $client = null;

    private ?Options $options = null;

    /**
     * The bundle's keys, read once, and the configuration of the options.
     *
     * @var array{excluded: list<string>, project: ?string, projects: array<array-key, ?string>, logger: ?string, options: array<array-key, mixed>}|null
     */
    private ?array $keys = null;

    /** @var array<int, string> the lines of bad values, written at the first server that AgentCat tracks */
    private array $lines = [];

    /** @var array<string, bool> the names of the servers that AgentCat tracks (D62) */
    private array $tracked = [];

    /**
     * @param \Closure(): mixed  $config    the processed agentcat configuration, an `\ArrayObject`, read when called
     * @param ContainerInterface $services  the services the configuration names, by ID: the hooks and the logger (a
     *                                      missing one is left out)
     * @param string             $logFile   the file of AgentCat's lines without a `logger` (D61)
     * @param list<string>       $servers   the name of every server (`agentcat.servers`), for the names of
     *                                      `exclude_servers`
     * @param list<string>       $variables the keys that name a service through an environment variable: the
     *                                      container knows a service by the ID written in the configuration only
     */
    public function __construct(
        private readonly \Closure $config,
        private readonly ContainerInterface $services,
        private readonly ?RequestStack $requests,
        private readonly string $logFile,
        private readonly array $servers = [],
        private readonly array $variables = [],
    ) {}

    public function track(Builder $builder, string $server): void
    {
        try {
            $config = ($this->config)();
            $config = $config instanceof \ArrayObject ? $config->getArrayCopy() : [];
            $keys = $this->keys;
            if ($keys === null) {
                [$keys, $this->lines] = $this->keys($config);
                $this->keys = $keys;
            }
            if (ServerNames::excluded($server, $keys['excluded'], $this->servers)) {
                return; // named through an environment variable: it touches neither the log nor diagnostics
            }
            $this->adoptLogger($keys['logger']);
            foreach ($this->lines as $line) {
                Log::write($line);
            }
            $this->lines = [];
            $client = AgentCat::client();
            $context = $this->requests instanceof RequestStack ? RequestStackContext::of($this->requests) : null;
            // A fake records every server (header conflict 6), with the request of each call, as production sends it
            // (D41); the shared client is never replaced.
            if (!FakeLog::of($client) instanceof FakeLog) {
                $client = $this->client ??= new Client(ClientSeams::of(EventQueue::global(), EnabledGate::of($config['enabled'] ?? null), httpContext: $context));
            } elseif ($context instanceof \Closure) {
                $client = $client->withHttpContext($context);
            }
            $this->options ??= ConfigOptions::options($keys['options'], self::BRIDGE_KEYS, $this->services->get(...));
            // A null entry (`%env(default::…)%` of an unset variable) is the configured project; without one, the
            // client reads AGENTCAT_PROJECT_ID (D36).
            $client->track($builder, $keys['projects'][$server] ?? $keys['project'], $this->options);
            if (TrackedServers::get($builder) instanceof TrackedServer) {
                $this->tracked[$server] = true; // a server that AgentCat did not track (off, or failed) keeps its answers
            }
        } catch (\Throwable $e) {
            $this->adoptLogger(null);
            Log::write('Warning: Failed to track server - ' . ErrorText::of($e));
        }
    }

    /** Whether AgentCat tracks the server of this name, in this container (D62: its answers get their length). */
    public function tracked(string $server): bool
    {
        return $this->tracked[$server] ?? false;
    }

    /**
     * The bundle's own keys, with their types, the configuration of the options, and the line of each bad value. A
     * service ID from an environment variable is unset, with a line: the locator holds the IDs written in the
     * configuration only.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array{array{excluded: list<string>, project: ?string, projects: array<array-key, ?string>, logger: ?string, options: array<array-key, mixed>}, array<int, string>}
     */
    private function keys(array $config): array
    {
        /** @var \ArrayObject<int, string> $lines */
        $lines = new \ArrayObject();
        foreach ($this->variables as $key) {
            if (($config[$key] ?? '') !== '') {
                $lines->append(sprintf(self::LITERAL_ID, $key));
            }
            $config[$key] = null;
        }
        $read = new BridgeKeys($config, static function (string $line) use ($lines): void {
            $lines->append($line);
        });
        $keys = [
            'excluded' => $read->strings('exclude_servers', []),
            'project' => $read->string('project_id'),
            'projects' => $read->projects(),
            'logger' => $read->string('logger'),
            'options' => $config,
        ];
        $read->string('diagnostics_endpoint'); // read at boot (BridgeConfig); here for the line of a bad value
        $read->string('diagnostics_token');

        return [$keys, $lines->getArrayCopy()];
    }

    /**
     * D61: AgentCat's lines go to the configured logger service, else to their own file,
     * `%kernel.logs_dir%/agentcat.log` (TypeScript writes `~/mcpcat.log`), not to the Builder's `mcp` logger (D30, D38):
     * under PHP-FPM every request builds the container and tracks the Builder again, so the setup lines repeat per
     * request, and they stay out of the application's log and its error trackers. The first adopted logger of the
     * process wins, and a fake's diversion keeps priority; after a fake, the next server routes the lines again.
     */
    private function adoptLogger(?string $id): void
    {
        if (Log::hasLogger()) {
            return;
        }
        $named = $id !== null && $id !== '';
        $logger = null;
        if ($named) {
            try {
                $logger = $this->services->get($id);
            } catch (\Throwable) {
                // not a service: the line below says so
            }
        }
        if ($logger instanceof LoggerInterface) {
            Log::adopt($logger);

            return;
        }
        Log::adopt(new LogFile($this->logFile)); // its directory is made at its first line
        if ($named) {
            Log::write(sprintf(self::NO_LOGGER, $id));
        }
    }
}
