<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract;

use AgentCat\Internal\Access\TouchesPrivateMember;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListToolsResult;

/**
 * The manifest of library internals AgentCat depends on, for Plan F's snapshot diff over `members()`: every
 * #[TouchesPrivateMember] in src/<Adapter>/Internal/Access, and every library class AgentCat extends.
 */
final class PrivateMemberManifest
{
    /**
     * The library classes that classes in src/<Adapter>/Internal extend, each with the constructor they call. A parent
     * that turns final or changes this constructor breaks them.
     */
    public const array EXTENDED = [
        CallToolResult::class => '__construct(array $content, bool $isError = false, mixed $structuredContent = null, ?array $meta = null)',
        ListToolsResult::class => '__construct(array $tools, ?string $nextCursor = null)',
    ];

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

    /**
     * The library classes that a class in src/<Adapter>/Internal extends, sorted.
     *
     * @return list<class-string>
     */
    public static function extendedClasses(): array
    {
        $src = dirname(__DIR__, 2) . '/src/';
        $parents = [];
        $files = glob($src . '*/Internal/*.php');
        foreach ($files === false ? [] : $files as $file) {
            $class = 'AgentCat\\' . str_replace('/', '\\', substr($file, strlen($src), -4));
            $parent = class_exists($class) ? get_parent_class($class) : false;
            if ($parent !== false && !str_starts_with($parent, 'AgentCat\\')) {
                $parents[] = $parent;
            }
        }
        $parents = array_values(array_unique($parents));
        sort($parents);

        return $parents;
    }

    /**
     * The private members AgentCat reads (`Class::$member`) and the constructors of the classes it extends
     * (`Class::__construct(...)`), sorted.
     *
     * @return list<string>
     */
    public static function members(): array
    {
        $members = array_map(
            static fn(array $entry): string => $entry['member']->class . '::$' . $entry['member']->member,
            self::entries(),
        );
        foreach (self::EXTENDED as $class => $constructor) {
            $members[] = $class . '::' . $constructor;
        }
        $members = array_values(array_unique($members));
        sort($members);

        return $members;
    }
}
