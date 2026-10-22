<?php

declare(strict_types=1);

namespace AgentCat\Internal\Access;

/**
 * Marks an accessor that reads a private member of an integrated library (D21). The attributes on the accessors in
 * src/<Adapter>/Internal/Access are the single manifest of those members; the contract tests check each one against
 * the installed library.
 *
 * @internal
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class TouchesPrivateMember
{
    /**
     * @param class-string $class the class that declares the member
     * @param string $member the property name without `$`
     * @param string $type `(string) ReflectionProperty::getType()`
     */
    public function __construct(
        public string $class,
        public string $member,
        public string $type,
        public string $visibility = 'private',
        public bool $readonly = false,
        public bool $static = false,
    ) {}
}
