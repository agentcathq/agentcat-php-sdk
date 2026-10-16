<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Support;

use AgentCat\Internal\Support\ClassName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClassNameTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function nameProvider(): iterable
    {
        yield 'global class' => ['RuntimeException', 'RuntimeException'];
        yield 'namespaced class' => ['App\Tools', 'App\Tools'];
        yield 'anonymous class' => ["class@anonymous\0/path:12\$0", 'class@anonymous'];
        yield 'anonymous subclass' => ["App\\Base@anonymous\0/path:3\$1", 'App\Base@anonymous'];
    }

    #[DataProvider('nameProvider')]
    public function testCutsAnonymousNamesAtTheNulByte(string $class, string $expected): void
    {
        $this->assertSame($expected, ClassName::of($class));
    }

    public function testRealAnonymousClasses(): void
    {
        $this->assertSame('class@anonymous', ClassName::of((new class {})::class));
        $this->assertSame('Countable@anonymous', ClassName::of((new class implements \Countable {
            #[\Override]
            public function count(): int
            {
                return 0;
            }
        })::class));
        $this->assertSame('RuntimeException@anonymous', ClassName::of((new class extends \RuntimeException {})::class));
    }
}
