<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

/**
 * Builds an object the way Laravel 11's container autowires it (`Container::build()` and `resolveClass()`): each
 * constructor parameter with a class type is built the same way, recursively; when that fails, the parameter's
 * default is used. Other parameters take their default. Only a class that cannot be instantiated fails.
 */
final class Autowire
{
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function make(string $class): object
    {
        $reflector = new \ReflectionClass($class);
        if (!$reflector->isInstantiable()) {
            throw new \LogicException($class . ' is not instantiable');
        }
        $arguments = [];
        foreach ($reflector->getConstructor()?->getParameters() ?? [] as $parameter) {
            $arguments[] = self::argument($parameter);
        }

        return $reflector->newInstanceArgs($arguments);
    }

    private static function argument(\ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            try {
                /** @var class-string $class */
                $class = $type->getName();

                return self::make($class);
            } catch (\LogicException $e) {
                if (!$parameter->isDefaultValueAvailable()) {
                    throw $e;
                }
            }
        }
        if (!$parameter->isDefaultValueAvailable()) {
            throw new \LogicException('$' . $parameter->getName() . ' has no default');
        }

        return $parameter->getDefaultValue();
    }
}
