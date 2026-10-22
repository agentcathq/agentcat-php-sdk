<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract;

use PHPUnit\Framework\Assert;

/**
 * The contract check of the #[TouchesPrivateMember] entries against the installed library, shared by the member
 * tests of every adapter. The test reads the attributes itself, so their code runs inside the test (coverage and
 * mutation testing see it; a data provider runs before either starts).
 */
final class MemberShape
{
    /**
     * One row per accessor that touches a member of a class in the namespace.
     *
     * @param string $namespace with its trailing backslash, for example `Mcp\`
     *
     * @return array<string, array{string}>
     */
    public static function accessorsIn(string $namespace): array
    {
        $rows = [];
        foreach (PrivateMemberManifest::entries() as $entry) {
            if (str_starts_with($entry['member']->class, $namespace)) {
                $rows[$entry['accessor']] = [$entry['accessor']];
            }
        }

        return $rows;
    }

    /** Asserts that every member the accessor declares still has the declared shape; each check is an assertion. */
    public static function assertDeclared(string $accessor): void
    {
        $members = array_column(array_filter(
            PrivateMemberManifest::entries(),
            static fn(array $entry): bool => $entry['accessor'] === $accessor,
        ), 'member');
        Assert::assertNotSame([], $members);

        foreach ($members as $member) {
            Assert::assertTrue(property_exists($member->class, $member->member), $member->class . '::$' . $member->member . ' is gone');

            $property = new \ReflectionProperty($member->class, $member->member);
            Assert::assertSame($member->class, $property->getDeclaringClass()->getName());
            Assert::assertSame($member->visibility === 'private', $property->isPrivate());
            Assert::assertSame($member->visibility === 'protected', $property->isProtected());
            Assert::assertSame($member->static, $property->isStatic());
            Assert::assertSame($member->readonly, $property->isReadOnly());
            Assert::assertSame($member->type, (string) $property->getType());
        }
    }
}
