<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\Snapshot;

use AgentCat\Tests\Contract\PrivateMemberManifest;
use Composer\InstalledVersions;

/**
 * Every member of every class of one library that AgentCat touches: the classes that carry a #[TouchesPrivateMember]
 * and the classes AgentCat extends (see PrivateMemberManifest). The snapshot is sorted and JSON-ready, so a committed
 * file per library version can be compared with the installed one, and the nightly job can diff `dev-main` against it.
 *
 * @phpstan-type ParameterShape array{byReference: bool, hasDefault: bool, name: string, type: string, variadic: bool}
 * @phpstan-type PropertyShape array{readonly: bool, static: bool, type: string, visibility: string}
 * @phpstan-type MethodShape array{abstract: bool, final: bool, parameters: list<ParameterShape>, return: string, static: bool, visibility: string}
 * @phpstan-type ConstantShape array{visibility: string}
 * @phpstan-type ClassShape array{constants: array<string, ConstantShape>, final: bool, methods: array<string, MethodShape>, properties: array<string, PropertyShape>}
 * @phpstan-type Snapshot array{classes: array<string, ClassShape>, library: string, version: string}
 */
final class MemberSnapshot
{
    /** The library's root namespace, by Composer package name. */
    private const array NAMESPACES = [
        'laravel/mcp' => 'Laravel\\Mcp\\',
        'mcp/sdk' => 'Mcp\\',
    ];

    /** @return list<string> the Composer package names a snapshot can be taken of */
    public static function libraries(): array
    {
        return array_keys(self::NAMESPACES);
    }

    /**
     * The installed version of the library, without a leading `v` (`0.8.1`; `dev-main` on a branch install).
     *
     * @throws \InvalidArgumentException when the library is unknown or not installed
     */
    public static function installedVersion(string $library): string
    {
        self::namespaceOf($library);
        $version = InstalledVersions::isInstalled($library) ? InstalledVersions::getPrettyVersion($library) : null;
        if ($version === null) {
            throw new \InvalidArgumentException($library . ' is not installed');
        }

        return ltrim($version, 'v');
    }

    /**
     * @return Snapshot
     *
     * @throws \InvalidArgumentException when the library is unknown or not installed
     */
    public static function of(string $library): array
    {
        $classes = [];
        foreach (self::classesOf($library) as $class) {
            $classes[$class] = self::ofClass($class);
        }
        ksort($classes, SORT_STRING);

        return ['classes' => $classes, 'library' => $library, 'version' => self::installedVersion($library)];
    }

    /**
     * The library's classes that the manifest names and that exist in the installed version, sorted.
     *
     * @return list<class-string>
     */
    public static function classesOf(string $library): array
    {
        $namespace = self::namespaceOf($library);
        $names = PrivateMemberManifest::extendedClasses();
        array_push($names, ...array_keys(PrivateMemberManifest::EXTENDED));
        foreach (PrivateMemberManifest::entries() as $entry) {
            $names[] = $entry['member']->class;
        }

        $classes = [];
        foreach (array_unique($names) as $name) {
            if (str_starts_with($name, $namespace) && class_exists($name)) {
                $classes[] = $name;
            }
        }
        sort($classes, SORT_STRING);

        return $classes;
    }

    /** @param Snapshot $snapshot */
    public static function toJson(array $snapshot): string
    {
        return json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @return Snapshot
     *
     * @throws \InvalidArgumentException when the text is not the JSON of a snapshot
     * @throws \JsonException
     */
    public static function fromJson(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['classes'] ?? null) || !is_string($data['library'] ?? null) || !is_string($data['version'] ?? null)) {
            throw new \InvalidArgumentException('not a member snapshot');
        }

        /** @var Snapshot $data */
        return $data;
    }

    /**
     * @param class-string $class
     *
     * @return ClassShape
     */
    private static function ofClass(string $class): array
    {
        $reflection = new \ReflectionClass($class);

        $constants = [];
        foreach ($reflection->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() === $class) {
                $constants[$constant->getName()] = ['visibility' => self::visibility($constant->isPublic(), $constant->isProtected())];
            }
        }

        $properties = [];
        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $class) {
                $properties[$property->getName()] = [
                    'readonly' => $property->isReadOnly(),
                    'static' => $property->isStatic(),
                    'type' => self::type($property->getType(), $class),
                    'visibility' => self::visibility($property->isPublic(), $property->isProtected()),
                ];
            }
        }

        $methods = [];
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === $class) {
                $parameters = [];
                foreach ($method->getParameters() as $parameter) {
                    $parameters[] = [
                        'byReference' => $parameter->isPassedByReference(),
                        'hasDefault' => $parameter->isDefaultValueAvailable(),
                        'name' => $parameter->getName(),
                        'type' => self::type($parameter->getType(), $class),
                        'variadic' => $parameter->isVariadic(),
                    ];
                }
                $methods[$method->getName()] = [
                    'abstract' => $method->isAbstract(),
                    'final' => $method->isFinal(),
                    'parameters' => $parameters,
                    'return' => self::type($method->getReturnType(), $class),
                    'static' => $method->isStatic(),
                    'visibility' => self::visibility($method->isPublic(), $method->isProtected()),
                ];
            }
        }

        ksort($constants, SORT_STRING);
        ksort($properties, SORT_STRING);
        ksort($methods, SORT_STRING);

        return ['constants' => $constants, 'final' => $reflection->isFinal(), 'methods' => $methods, 'properties' => $properties];
    }

    /**
     * The type as written, with `self` spelled as the class: PHP 8.5 reports `self` as the class name, earlier versions
     * as `self`, and a snapshot must not depend on the PHP version that took it.
     */
    private static function type(?\ReflectionType $type, string $class): string
    {
        return preg_replace('/(?<![\\\\\w])self(?![\\\\\w])/', addcslashes($class, '\\$'), (string) $type) ?? (string) $type;
    }

    private static function visibility(bool $public, bool $protected): string
    {
        return $public ? 'public' : ($protected ? 'protected' : 'private');
    }

    private static function namespaceOf(string $library): string
    {
        return self::NAMESPACES[$library] ?? throw new \InvalidArgumentException('unknown library ' . $library . ' (expected ' . implode(' or ', self::libraries()) . ')');
    }
}
