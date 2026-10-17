<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract;

use AgentCat\Internal\Access\TouchesPrivateMember;

/** The manifest of private members AgentCat touches: every #[TouchesPrivateMember] in src/<Adapter>/Internal/Access. */
final class PrivateMemberManifest
{
    /** @return list<class-string> */
    public static function accessorClasses(): array
    {
        $src = dirname(__DIR__, 2) . '/src/';
        $classes = [];
        $files = glob($src . '*/Internal/Access/*.php');
        foreach ($files === false ? [] : $files as $file) {
            $class = 'AgentCat\\' . str_replace('/', '\\', substr($file, strlen($src), -4));
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }

    /** @return list<array{accessor: string, member: TouchesPrivateMember}> */
    public static function entries(): array
    {
        $entries = [];
        foreach (self::accessorClasses() as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_STATIC | \ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(TouchesPrivateMember::class) as $attribute) {
                    $entries[] = ['accessor' => $class . '::' . $method->getName(), 'member' => $attribute->newInstance()];
                }
            }
        }

        return $entries;
    }

    /** @return list<string> */
    public static function members(): array
    {
        $members = array_values(array_unique(array_map(
            static fn(array $entry): string => $entry['member']->class . '::$' . $entry['member']->member,
            self::entries(),
        )));
        sort($members);

        return $members;
    }
}
