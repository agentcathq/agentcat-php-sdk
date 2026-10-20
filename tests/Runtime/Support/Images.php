<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime\Support;

/**
 * The Docker images of the runtime tests, pinned by tag (header conflict 10). The CI job pulls them before the suite,
 * and builds `cli()` and `swoole()`; a test never pulls (`docker run --pull never`).
 *
 * With `AGENTCAT_RUNTIME_IMAGES=latest` (the nightly `runtime-latest` job) every method returns the floating tag of
 * the same line instead: the latest patch of PHP 8.3, FrankenPHP 1, Swoole 6 and Unit. The two local images are then
 * built with `--build-arg BASE=<floating tag>` and tagged `:latest`.
 */
final class Images
{
    public const string ENVIRONMENT_VARIABLE = 'AGENTCAT_RUNTIME_IMAGES';

    /** The `--build-arg BASE` of `tests/Runtime/docker/Dockerfile` in latest mode. */
    public const string CLI_BASE_LATEST = 'php:8.3-cli-bookworm';

    /**
     * The `--build-arg BASE` of `tests/Runtime/docker/swoole/Dockerfile` in latest mode. The image has no `6-php8.3`
     * tag: `php8.3` is the newest Swoole on PHP 8.3 (6.3.0RC1 on 2026-10-20), so the nightly sees a Swoole 6.3 release early.
     */
    public const string SWOOLE_BASE_LATEST = 'phpswoole/swoole:php8.3';

    /** Its stop signal is SIGQUIT: a graceful stop. */
    public static function fpm(): string
    {
        return self::pick('php:8.3.35-fpm-bookworm', 'php:8.3-fpm-bookworm');
    }

    /** Its stop signal is SIGWINCH: a graceful stop. */
    public static function apache(): string
    {
        return self::pick('php:8.3.35-apache-bookworm', 'php:8.3-apache-bookworm');
    }

    /**
     * Built locally: `docker build --tag agentcat-runtime-cli:8.3.35 tests/Runtime/docker` (the official image plus
     * ext-pcntl). Its stop signal is SIGTERM, as for `frankenphp()`, `swoole()` and `unit()`.
     */
    public static function cli(): string
    {
        return self::pick('agentcat-runtime-cli:8.3.35', 'agentcat-runtime-cli:latest');
    }

    public static function frankenphp(): string
    {
        return self::pick('dunglas/frankenphp:1.13.1-php8.3.35-bookworm', 'dunglas/frankenphp:1-php8.3-bookworm');
    }

    /**
     * Built locally: `docker build --tag agentcat-runtime-swoole:6.2.3-php8.3 tests/Runtime/docker/swoole`
     * (`phpswoole/swoole:6.2.3-php8.3`, PHP 8.3.33, plus ext-pcntl). Its entrypoint splits the command at spaces
     * (`exec $@`): run a script, not `php -r`.
     */
    public static function swoole(): string
    {
        return self::pick('agentcat-runtime-swoole:6.2.3-php8.3', 'agentcat-runtime-swoole:latest');
    }

    /** NGINX Unit with PHP 8.3.26 (ruling P19). */
    public static function unit(): string
    {
        return self::pick('unit:1.34.2-php8.3', 'unit:php8.3');
    }

    /**
     * The images whose main process is PHP CLI: PHP must not be PID 1 there (header conflict 11).
     *
     * @return list<string>
     */
    public static function initImages(): array
    {
        return [self::cli(), self::swoole()];
    }

    private static function pick(string $pinned, string $latest): string
    {
        $mode = getenv(self::ENVIRONMENT_VARIABLE);
        if ($mode === false || $mode === '') {
            return $pinned;
        }
        if ($mode === 'latest') {
            return $latest;
        }

        throw new \InvalidArgumentException(sprintf('%s must be empty or "latest", got "%s".', self::ENVIRONMENT_VARIABLE, $mode));
    }
}
