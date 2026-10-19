<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime\Support;

/**
 * The Docker images of the runtime tests, pinned by tag (header conflict 10). The CI job pulls them before the suite,
 * and builds `CLI` and `SWOOLE`; a test never pulls (`docker run --pull never`).
 */
final class Images
{
    /** Its stop signal is SIGQUIT: a graceful stop. */
    public const string FPM = 'php:8.3.35-fpm-bookworm';

    /** Its stop signal is SIGWINCH: a graceful stop. */
    public const string APACHE = 'php:8.3.35-apache-bookworm';

    /**
     * Built locally: `docker build --tag agentcat-runtime-cli:8.3.35 tests/Runtime/docker` (the official image plus
     * ext-pcntl). Its stop signal is SIGTERM, as for `FRANKENPHP`, `SWOOLE` and `UNIT`.
     */
    public const string CLI = 'agentcat-runtime-cli:8.3.35';

    public const string FRANKENPHP = 'dunglas/frankenphp:1.13.1-php8.3.35-bookworm';

    /**
     * Built locally: `docker build --tag agentcat-runtime-swoole:6.2.3-php8.3 tests/Runtime/docker/swoole`
     * (`phpswoole/swoole:6.2.3-php8.3`, PHP 8.3.33, plus ext-pcntl). Its entrypoint splits the command at spaces
     * (`exec $@`): run a script, not `php -r`.
     */
    public const string SWOOLE = 'agentcat-runtime-swoole:6.2.3-php8.3';

    /** NGINX Unit with PHP 8.3.26 (ruling P19). */
    public const string UNIT = 'unit:1.34.2-php8.3';
}
