<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

/**
 * The arguments each test tool received, per tool name. The laravel/mcp and symfony/mcp-bundle test tools record here;
 * their base test cases reset it.
 */
final class ToolCalls
{
    /** @var array<string, list<array<string, mixed>>> */
    private static array $calls = [];

    /** @param array<string, mixed> $arguments */
    public static function record(string $tool, array $arguments): void
    {
        self::$calls[$tool][] = $arguments;
    }

    /** @return list<array<string, mixed>> the arguments of each call of the tool, in order */
    public static function of(string $tool): array
    {
        return self::$calls[$tool] ?? [];
    }

    /** Forgets every call. */
    public static function reset(): void
    {
        self::$calls = [];
    }
}
