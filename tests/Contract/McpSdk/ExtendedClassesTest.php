<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\McpSdk;

use AgentCat\Tests\Contract\PrivateMemberManifest;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListToolsResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * AgentCat answers `tools/list` and `tools/call` with subclasses of the library's result classes, so that a
 * customer's `ResponseEvent` listeners keep seeing the library's types. A future mcp/sdk that makes a parent final,
 * readonly or its `jsonSerialize()` final, or that changes its constructor, breaks them. For a server tracked after
 * build(), AgentCat's `InjectedSchemaValidator` extends the library's schema validator and overrides its one method.
 */
#[Group('contract')]
final class ExtendedClassesTest extends TestCase
{
    public function testTheManifestListsEveryLibraryClassAgentCatExtends(): void
    {
        $this->assertSame([SchemaValidator::class, CallToolResult::class, ListToolsResult::class], $this->mcpClasses(PrivateMemberManifest::extendedClasses()));
        $this->assertSame([SchemaValidator::class, CallToolResult::class, ListToolsResult::class], $this->mcpClasses(array_keys(PrivateMemberManifest::EXTENDED)));
    }

    /** @return \Iterator<string, array{class-string}> one row per extended mcp/sdk result class */
    public static function extendedClasses(): \Iterator
    {
        foreach ([CallToolResult::class, ListToolsResult::class] as $class) {
            yield $class => [$class];
        }
    }

    public function testTheSchemaValidatorStillHasTheMethodAgentCatOverrides(): void
    {
        $parent = new \ReflectionClass(SchemaValidator::class);
        $method = $parent->getMethod('validateAgainstJsonSchema');
        $constructor = $parent->getConstructor();

        $this->assertFalse($parent->isFinal(), 'SchemaValidator is final');
        $this->assertFalse($method->isFinal(), 'validateAgainstJsonSchema() is final');
        $this->assertTrue($method->isPublic());
        $this->assertSame(PrivateMemberManifest::EXTENDED[SchemaValidator::class], $this->signature($method) . ': ' . $method->getReturnType());
        $this->assertInstanceOf(\ReflectionMethod::class, $constructor);
        $this->assertSame(0, $constructor->getNumberOfRequiredParameters(), 'InjectedSchemaValidator passes the logger only');
        $logger = array_values(array_filter($constructor->getParameters(), static fn(\ReflectionParameter $parameter): bool => $parameter->getName() === 'logger'));
        $this->assertCount(1, $logger, 'InjectedSchemaValidator passes it as the named argument logger');
        $this->assertSame(LoggerInterface::class, (string) $logger[0]->getType());
    }

    /** @param class-string $class */
    #[DataProvider('extendedClasses')]
    public function testTheParentCanStillBeExtendedWithTheSameConstructor(string $class): void
    {
        $parent = new \ReflectionClass($class);

        $this->assertFalse($parent->isFinal(), $class . ' is final');
        $this->assertFalse($parent->isReadOnly(), $class . ' is readonly');
        $this->assertFalse($parent->getMethod('jsonSerialize')->isFinal(), $class . '::jsonSerialize() is final');
        $constructor = $parent->getConstructor();
        $this->assertInstanceOf(\ReflectionMethod::class, $constructor);
        $this->assertTrue($constructor->isPublic());
        $this->assertSame(PrivateMemberManifest::EXTENDED[$class] ?? null, $this->signature($constructor));
        foreach ($constructor->getParameters() as $parameter) {
            $this->assertTrue($parameter->isPromoted(), $class . '::$' . $parameter->getName() . ' is no longer a promoted property');
            $property = $parent->getProperty($parameter->getName());
            $this->assertTrue($property->isPublic() && $property->isReadOnly(), $class . '::$' . $parameter->getName() . ' is not public readonly');
        }
    }

    /**
     * The mcp/sdk classes of a list of classes, in order.
     *
     * @param list<class-string> $classes
     *
     * @return list<class-string>
     */
    private function mcpClasses(array $classes): array
    {
        return array_values(array_filter($classes, static fn(string $class): bool => str_starts_with($class, 'Mcp\\')));
    }

    /** `name(type $parameter = default, ...)`, defaults as JSON. */
    private function signature(\ReflectionMethod $method): string
    {
        $parameters = array_map(static function (\ReflectionParameter $parameter): string {
            $text = $parameter->getType() . ' $' . $parameter->getName();

            return $parameter->isDefaultValueAvailable()
                ? $text . ' = ' . json_encode($parameter->getDefaultValue(), JSON_THROW_ON_ERROR)
                : $text;
        }, $method->getParameters());

        return $method->getName() . '(' . implode(', ', $parameters) . ')';
    }
}
