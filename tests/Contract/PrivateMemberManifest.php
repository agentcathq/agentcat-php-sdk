<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract;

use AgentCat\Internal\Access\TouchesPrivateMember;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ToolInvoker;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListToolsResult;

/**
 * The manifest of library internals AgentCat depends on, for Plan F's snapshot diff over `members()`: every
 * #[TouchesPrivateMember] in src/<Adapter>/Internal/Access, every library class AgentCat extends, and the
 * symfony/mcp-bundle service definitions AgentCat's bundle reads at compile time.
 */
final class PrivateMemberManifest
{
    /**
     * The library classes that classes in src/<Adapter>/Internal extend, each with the method they depend on: the
     * constructor they call, or the method they override. A parent that turns final or changes this method breaks them.
     */
    public const array EXTENDED = [
        CallTool::class => 'handle(Laravel\\Mcp\\Transport\\JsonRpcRequest $request, Laravel\\Mcp\\Server\\ServerContext $context): Generator|Laravel\\Mcp\\Transport\\JsonRpcResponse',
        ToolInvoker::class => 'toErrorResponse(Throwable $e): Laravel\\Mcp\\Response',
        CallToolResult::class => '__construct(array $content, bool $isError = false, mixed $structuredContent = null, ?array $meta = null)',
        ListToolsResult::class => '__construct(array $tools, ?string $nextCursor = null)',
    ];

    /**
     * The symfony/mcp-bundle service IDs, tags and method calls that AgentCat's bundle finds each server builder by, as
     * strings: the bundle never names a class of symfony/mcp-bundle. `tests/Contract/Symfony` checks each one.
     */
    public const array BUNDLE_SERVICES = [
        'symfony/mcp-bundle: builder method call addRequestHandlers(!tagged_iterator mcp.request_handler)',
        'symfony/mcp-bundle: builder without a configurator',
        'symfony/mcp-bundle: service mcp.server.<name>.builder tagged mcp.server.builder {server: <name>}',
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
     * The private members AgentCat reads (`Class::$member`), the methods of the classes it extends that it depends on
     * (`Class::__construct(...)`, `Class::handle(...): …`) and the bundle service definitions it reads, sorted.
     *
     * @return list<string>
     */
    public static function members(): array
    {
        $members = array_map(
            static fn(array $entry): string => $entry['member']->class . '::$' . $entry['member']->member,
            self::entries(),
        );
        foreach (self::EXTENDED as $class => $method) {
            $members[] = $class . '::' . $method;
        }
        array_push($members, ...self::BUNDLE_SERVICES);
        $members = array_values(array_unique($members));
        sort($members);

        return $members;
    }
}
