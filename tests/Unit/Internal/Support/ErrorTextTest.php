<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Support;

use AgentCat\Internal\Support\ErrorText;
use PHPUnit\Framework\TestCase;

final class ErrorTextTest extends TestCase
{
    public function testFormatsClassAndMessage(): void
    {
        $this->assertSame('RuntimeException: Hook failure', ErrorText::of(new \RuntimeException('Hook failure')));
    }

    public function testUsesFullyQualifiedClassName(): void
    {
        $this->assertSame('Random\RandomException: boom', ErrorText::of(new \Random\RandomException('boom')));
    }

    public function testCutsAnonymousClassNamesAtTheNulByte(): void
    {
        $this->assertSame('RuntimeException@anonymous: boom', ErrorText::of(new class ('boom') extends \RuntimeException {}));
    }
}
