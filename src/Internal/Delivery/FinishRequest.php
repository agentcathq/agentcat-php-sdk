<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

use AgentCat\Internal\Support\Env;

/**
 * The SAPI function that sends the HTTP response to the client before the script ends, so that delivery at
 * shutdown does not delay the response.
 *
 * @internal
 */
final class FinishRequest
{
    /**
     * PHP-FPM `fastcgi_finish_request`, LiteSpeed `litespeed_finish_request`, FrankenPHP (classic mode)
     * `frankenphp_finish_request` or its `fastcgi_finish_request` alias, NGINX Unit (`isUnit()`)
     * `fastcgi_finish_request`; null elsewhere. FrankenPHP worker mode (`isFrankenPhpWorker()`) gets null: its shutdown
     * functions do not run per request.
     *
     * @param array<array-key, mixed>               $server         `$_SERVER`
     * @param \Closure(string): bool                $functionExists
     * @param (\Closure(string): (string|false))|null $getenv         default `Env::lookup()`
     */
    public static function functionFor(string $sapi, array $server, \Closure $functionExists, ?\Closure $getenv = null): ?string
    {
        $function = match (true) {
            $sapi === 'fpm-fcgi' => 'fastcgi_finish_request',
            $sapi === 'litespeed' => 'litespeed_finish_request',
            $sapi === 'frankenphp' && !self::isFrankenPhpWorker($server, $getenv) => $functionExists('frankenphp_finish_request') ? 'frankenphp_finish_request' : 'fastcgi_finish_request',
            $sapi === 'cli-server' && self::isUnit($server) => 'fastcgi_finish_request',
            default => null,
        };

        return $function !== null && $functionExists($function) ? $function : null;
    }

    /**
     * FrankenPHP worker mode: `$_SERVER['FRANKENPHP_WORKER']` is set, or the `FRANKENPHP_WORKER` environment variable.
     *
     * @param array<array-key, mixed>               $server `$_SERVER`
     * @param (\Closure(string): (string|false))|null $getenv default `Env::lookup()`
     */
    public static function isFrankenPhpWorker(array $server, ?\Closure $getenv = null): bool
    {
        return isset($server['FRANKENPHP_WORKER']) || ($getenv ?? Env::lookup(...))('FRANKENPHP_WORKER') !== false;
    }

    /**
     * NGINX Unit: its PHP module reports the SAPI of `php -S` (`cli-server`), and Unit names itself in
     * `SERVER_SOFTWARE` (`Unit/<version>`), where `php -S` names PHP's development server.
     *
     * @param array<array-key, mixed> $server `$_SERVER`
     */
    private static function isUnit(array $server): bool
    {
        $software = $server['SERVER_SOFTWARE'] ?? null;

        return is_string($software) && str_starts_with($software, 'Unit/');
    }

    /**
     * The process outlives the request: the command line (Octane on Swoole or RoadRunner, a Symfony Runtime worker, a
     * stdio server) or FrankenPHP worker mode.
     *
     * @param array<array-key, mixed>               $server `$_SERVER`
     * @param (\Closure(string): (string|false))|null $getenv default `Env::lookup()`
     */
    public static function outlivesRequest(string $sapi, array $server, ?\Closure $getenv = null): bool
    {
        return $sapi === 'cli' || $sapi === 'phpdbg' || ($sapi === 'frankenphp' && self::isFrankenPhpWorker($server, $getenv));
    }

    /**
     * A web SAPI that runs one request per script and has no finish-request function (mod_php, php-cgi, `php -S`):
     * the client has a response only when the script ends, unless the response carries its length (D57, D59).
     *
     * @param array<array-key, mixed>               $server         `$_SERVER`
     * @param \Closure(string): bool                $functionExists
     * @param (\Closure(string): (string|false))|null $getenv         default `Env::lookup()`
     */
    public static function responseEndsWithTheScript(string $sapi, array $server, \Closure $functionExists, ?\Closure $getenv = null): bool
    {
        return !self::outlivesRequest($sapi, $server, $getenv) && self::functionFor($sapi, $server, $functionExists, $getenv) === null;
    }

    /** @return (\Closure(): void)|null the finish call of this SAPI, if it has one */
    public static function detect(): ?\Closure
    {
        return self::closureFor(self::functionFor(PHP_SAPI, $_SERVER, static fn(string $name): bool => function_exists($name)));
    }

    /**
     * Calls `$function` after `ignore_user_abort(true)`, which keeps the script alive when the client
     * disconnects after its response. A host can disable `ignore_user_abort()` (`disable_functions`): the call then
     * runs alone.
     *
     * @param (\Closure(string): bool)|null $functionExists tests only: tells whether `ignore_user_abort()` exists; default `function_exists()`
     *
     * @return (\Closure(): void)|null null when `$function` is not callable
     */
    public static function closureFor(?string $function, ?\Closure $functionExists = null): ?\Closure
    {
        if ($function === null || !is_callable($function)) {
            return null;
        }
        $ignoreAbort = ($functionExists ?? function_exists(...))('ignore_user_abort');

        return static function () use ($function, $ignoreAbort): void {
            if ($ignoreAbort) {
                ignore_user_abort(true);
            }
            $function();
        };
    }
}
