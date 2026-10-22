<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

/**
 * Clears environment variables for one test and puts them back afterwards, in the three places `Env::get()` reads:
 * `getenv()`, `$_ENV` and `$_SERVER`.
 */
final readonly class Environment
{
    /** @param array<string, array{getenv: string|false, env: mixed, server: mixed}> $saved */
    private function __construct(private array $saved) {}

    /** Saves and clears the variables; the test calls `restore()` in `tearDown()`. */
    public static function clear(string ...$names): self
    {
        $saved = [];
        foreach ($names as $name) {
            $saved[$name] = ['getenv' => getenv($name), 'env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null];
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        return new self($saved);
    }

    /** Puts back the values `clear()` saved. */
    public function restore(): void
    {
        foreach ($this->saved as $name => $saved) {
            putenv($saved['getenv'] === false ? $name : $name . '=' . $saved['getenv']);
            unset($_ENV[$name], $_SERVER[$name]);
            if ($saved['env'] !== null) {
                $_ENV[$name] = $saved['env'];
            }
            if ($saved['server'] !== null) {
                $_SERVER[$name] = $saved['server'];
            }
        }
    }
}
