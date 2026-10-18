<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp\Internal;

use Illuminate\Container\Container;
use Laravel\Mcp\Server\Contracts\Method;

/**
 * The handler that an entry of a server's method map named before AgentCat's, resolved the way laravel/mcp resolves
 * an entry: with `Container::make()`, at each request.
 *
 * @internal
 */
final class Delegate
{
    /**
     * @param class-string<Method> $class
     *
     * @throws \UnexpectedValueException when the container resolves the class to an object that is not a method,
     *                                   which fails the request like laravel/mcp's own call would
     */
    public static function of(string $class): Method
    {
        $method = Container::getInstance()->make($class);

        return $method instanceof Method ? $method : throw new \UnexpectedValueException(sprintf('The container resolved %s to %s, which is not a %s', $class, get_debug_type($method), Method::class));
    }
}
