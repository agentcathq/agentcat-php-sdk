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

    /** @return iterable<string, array{string, string}> */
    public static function functionProvider(): iterable
    {
        yield 'method' => ['weather', 'weather'];
        yield 'php 8.4 closure' => ['{closure:/app/a.php:3}', '{closure:/app/a.php:3}'];
        yield 'closure in an anonymous class' => ["{closure:class@anonymous\0/app/a.php:12\$0::run():13}", '{closure:class@anonymous::run():13}'];
        yield 'closure in an anonymous subclass' => ["{closure:App\\Marker@anonymous\0/app/b.php:13\$1::run():13}", '{closure:App\Marker@anonymous::run():13}'];
        yield 'closure in an eval anonymous class' => ["{closure:class@anonymous\0/app/a.php(2) : eval()'d code:1\$2f1::run():1}", '{closure:class@anonymous::run():1}'];
        yield 'nested anonymous classes' => [
            "{closure:{closure:class@anonymous\0/a.php:3\$0::run():4}:5} {closure:class@anonymous\0/b.php:9\$a::go():9}",
            '{closure:{closure:class@anonymous::run():4}:5} {closure:class@anonymous::go():9}',
        ];
    }

    #[DataProvider('functionProvider')]
    public function testCutsAnonymousClassNamesInsideFunctionNames(string $function, string $expected): void
    {
        $this->assertSame($expected, ClassName::inFunction($function));
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
