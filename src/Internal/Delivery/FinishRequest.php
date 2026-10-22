<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

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
     * `frankenphp_finish_request` or its `fastcgi_finish_request` alias; null elsewhere. FrankenPHP worker mode
     * gets null: its shutdown functions do not run per request.
     *
     * @param array<array-key, mixed> $server         `$_SERVER`
     * @param \Closure(string): bool  $functionExists
     */
    public static function functionFor(string $sapi, array $server, \Closure $functionExists): ?string
    {
        $function = match (true) {
            $sapi === 'fpm-fcgi' => 'fastcgi_finish_request',
            $sapi === 'litespeed' => 'litespeed_finish_request',
            $sapi === 'frankenphp' && !isset($server['FRANKENPHP_WORKER']) => $functionExists('frankenphp_finish_request') ? 'frankenphp_finish_request' : 'fastcgi_finish_request',
            default => null,
        };

        return $function !== null && $functionExists($function) ? $function : null;
    }

    /** @return (\Closure(): void)|null the finish call of this SAPI, if it has one */
    public static function detect(): ?\Closure
    {
        return self::closureFor(self::functionFor(PHP_SAPI, $_SERVER, static fn(string $name): bool => function_exists($name)));
    }

    /**
     * Calls `$function` after `ignore_user_abort(true)`, which keeps the script alive when the client
     * disconnects after its response.
     *
     * @return (\Closure(): void)|null null when `$function` is not callable
     */
    public static function closureFor(?string $function): ?\Closure
    {
        if ($function === null || !is_callable($function)) {
            return null;
        }

        return static function () use ($function): void {
            ignore_user_abort(true);
            $function();
        };
    }
}
