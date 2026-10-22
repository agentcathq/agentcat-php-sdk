<?php

declare(strict_types=1);

namespace AgentCat\Internal\Support;

/**
 * The keys of a framework bridge's `agentcat` configuration that the bridge reads itself (not options), read with their
 * types: a value of a wrong type logs one line through `$warn`, and the default is used, so a typo never goes unseen.
 * Null is an unset value (`env()` of an unset variable, `%env(default::…)%` in Symfony): the default, no line. The
 * lines name the key as the customer wrote it, `agentcat.<key>`.
 *
 * @internal
 */
final readonly class BridgeKeys
{
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

    /** A string, or null: a project ID, a log channel, a service ID, the diagnostics endpoint or token. */
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
     * A list of strings, such as the server classes AgentCat never tracks. A string is a list of one, but a blank string
     * names nothing: the default, with one line. A null entry is unset.
     *
     * @param list<string> $default the value when the key is unset, and when it is of a wrong type
     *
     * @return list<string>
     */
    public function strings(string $key, array $default): array
    {
        $value = $this->config[$key] ?? $default;
        if (is_string($value) && trim($value) === '') {
            ($this->warn)(sprintf(self::WRONG_TYPE, $key, 'array or non-empty string', $value === '' ? 'empty string' : 'a blank string')); // names no server

            return $default;
        }
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            $this->wrongType($key, 'array or string', $value);

            return $default;
        }
        $strings = [];
        foreach ($value as $index => $entry) {
            if (is_string($entry)) {
                $strings[] = $entry;
            } elseif ($entry !== null) {
                ($this->warn)(sprintf(self::WRONG_ENTRY, $key, $index, 'string', get_debug_type($entry)));
            }
        }

        return $strings;
    }

    /**
     * `projects`: a server (its class in Laravel, its name in Symfony) => project ID; a null project is the configured
     * `project_id`.
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
        foreach ($value as $server => $project) {
            if ($project === null || is_string($project)) {
                $projects[$server] = $project;
            } else {
                ($this->warn)(sprintf(self::WRONG_ENTRY, 'projects', $server, 'string or null', get_debug_type($project)));
            }
        }

        return $projects;
    }

    private function wrongType(string $key, string $expected, mixed $value): void
    {
        ($this->warn)(sprintf(self::WRONG_TYPE, $key, $expected, get_debug_type($value)));
    }
}
