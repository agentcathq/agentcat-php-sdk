<?php

declare(strict_types=1);

namespace AgentCat\Symfony\Internal;

use AgentCat\AgentCat;
use AgentCat\Client;
use AgentCat\Internal\ClientSeams;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Logging\FileLogger;
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
 * One tracker serves the container (a worker's too). At the first server it tracks, it reads the configuration, whose
 * environment variables resolve then, and builds its client and the options, so each bad value logs once. A
 * configuration that cannot be read (a variable without a default that is not set) logs one line for each server, which
 * then answers untracked.
 *
 * @internal
 */
final class ServerTracker
{
    /** @var list<string> The keys of the agentcat configuration that the bundle reads itself; the others are options. */
    public const array BRIDGE_KEYS = ['enabled', 'project_id', 'exclude_servers', 'projects', 'logger', 'diagnostics_endpoint', 'diagnostics_token'];

    private const string NO_LOGGER = 'WARN: AgentCat option "agentcat.logger" must be the ID of a PSR-3 logger service, got "%s"; the default is used';

    private ?Client $client = null;

    private ?Options $options = null;

    /** @var array{project: ?string, projects: array<array-key, ?string>, logger: ?string}|null the bundle's keys, read once */
    private ?array $keys = null;

    /**
     * @param \Closure(): mixed  $config   the processed agentcat configuration, an `\ArrayObject`, read when called
     * @param ContainerInterface $services the services the configuration names, by ID: the hooks and the logger (a
     *                                     missing one is left out)
     * @param string             $logFile  the file of AgentCat's lines without a `logger` (D61)
     */
    public function __construct(
        private readonly \Closure $config,
        private readonly ContainerInterface $services,
        private readonly ?RequestStack $requests,
        private readonly string $logFile,
    ) {}

    public function track(Builder $builder, string $server): void
    {
        try {
            $config = ($this->config)();
            $config = $config instanceof \ArrayObject ? $config->getArrayCopy() : [];
            $keys = $this->keys;
            $lines = [];
            if ($keys === null) {
                [$keys, $lines] = $this->keys($config);
                $this->keys = $keys;
            }
            $this->adoptLogger($keys['logger']);
            foreach ($lines as $line) {
                Log::write($line);
            }
            $client = AgentCat::client();
            // A fake records every server (header conflict 6); the shared client is never replaced.
            if (!FakeLog::of($client) instanceof FakeLog) {
                $client = $this->client ??= new Client(ClientSeams::of(
                    EventQueue::global(),
                    EnabledGate::of($config['enabled'] ?? null),
                    httpContext: $this->requests instanceof RequestStack ? RequestStackContext::of($this->requests) : null,
                ));
            }
            $this->options ??= ConfigOptions::options($config, self::BRIDGE_KEYS, $this->services->get(...));
            // A null entry (`%env(default::…)%` of an unset variable) is the configured project; without one, the
            // client reads AGENTCAT_PROJECT_ID (D36).
            $client->track($builder, $keys['projects'][$server] ?? $keys['project'], $this->options);
        } catch (\Throwable $e) {
            $this->adoptLogger(null);
            Log::write('Warning: Failed to track server - ' . ErrorText::of($e));
        }
    }

    /**
     * The bundle's own keys, with their types, and the line of each bad value.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array{array{project: ?string, projects: array<array-key, ?string>, logger: ?string}, array<int, string>}
     */
    private function keys(array $config): array
    {
        /** @var \ArrayObject<int, string> $lines */
        $lines = new \ArrayObject();
        $read = new BridgeKeys($config, static function (string $line) use ($lines): void {
            $lines->append($line);
        });
        $keys = ['project' => $read->string('project_id'), 'projects' => $read->projects(), 'logger' => $read->string('logger')];
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
        $directory = dirname($this->logFile);
        if (!is_dir($directory)) {
            @mkdir($directory, recursive: true); // 0777 less the umask; Symfony makes its log directory only for a write
        }
        Log::adopt(new FileLogger($this->logFile));
        if ($named) {
            Log::write(sprintf(self::NO_LOGGER, $id));
        }
    }
}
