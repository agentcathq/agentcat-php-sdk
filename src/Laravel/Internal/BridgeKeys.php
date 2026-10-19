<?php

declare(strict_types=1);

namespace AgentCat\Laravel\Internal;

/**
 * The keys of config/agentcat.php that the bridge reads itself (not options), read with their types (Review Focus
 * 3): a value of a wrong type logs one line through `$warn`, and the default is used, so a typo never goes unseen. A
 * string `exclude` is a list of one class. Null is an unset value (`env()` of an unset variable): the default, no line.
 *
 * @internal
 */
final readonly class BridgeKeys
{
    /** config/agentcat.php's `exclude`: the default of a value of a wrong type too. */
    public const array DEFAULT_EXCLUDE = ['Laravel\Boost\Mcp\Boost'];

    private const string WRONG_TYPE = 'WARN: AgentCat option "agentcat.%s" must be %s, got %s; the default is used';

    private const string WRONG_ENTRY = 'WARN: AgentCat option "agentcat.%s.%s" must be %s, got %s; the entry is ignored';

    /**
     * @param array<array-key, mixed> $config the bridge's `agentcat` configuration
     * @param \Closure(string): void  $warn   gets the line of each bad value
     */
    public function __construct(
        private array $config,
        private \Closure $warn,
    ) {}

    /** `project_id`, `log_channel`, `diagnostics_endpoint` or `diagnostics_token`: a string, or null. */
    public function string(string $key): ?string
    {
        $value = $this->config[$key] ?? null;
        if ($value === null || is_string($value)) {
            return $value;
        }
        $this->wrongType($key, 'string or null', $value);

        return null;
    }

    /**
     * The server classes AgentCat never tracks.
     *
     * @return list<string>
     */
    public function exclude(): array
    {
        $value = $this->config['exclude'] ?? self::DEFAULT_EXCLUDE;
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            $this->wrongType('exclude', 'array or string', $value);

            return self::DEFAULT_EXCLUDE;
        }
        $classes = [];
        foreach ($value as $index => $class) {
            if (is_string($class)) {
                $classes[] = $class;
            } elseif ($class !== null) {
                ($this->warn)(sprintf(self::WRONG_ENTRY, 'exclude', $index, 'string', get_debug_type($class)));
            }
        }

        return $classes;
    }

    /**
     * Server class => project ID; a null project is the configured `project_id`.
     *
     * @return array<array-key, string|null>
     */
    public function projects(): array
    {
        $value = $this->config['projects'] ?? [];
        if (!is_array($value)) {
            $this->wrongType('projects', 'array or null', $value);

            return [];
        }
        $projects = [];
        foreach ($value as $class => $project) {
            if ($project === null || is_string($project)) {
                $projects[$class] = $project;
            } else {
                ($this->warn)(sprintf(self::WRONG_ENTRY, 'projects', $class, 'string or null', get_debug_type($project)));
            }
        }

        return $projects;
    }

    private function wrongType(string $key, string $expected, mixed $value): void
    {
        ($this->warn)(sprintf(self::WRONG_TYPE, $key, $expected, get_debug_type($value)));
    }
}
