<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Support;

use AgentCat\Internal\Support\ErrorText;
use AgentCat\Tests\Fixtures\Errors\LegacyMessageException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{mixed, string}> */
    public static function legacyMessageProvider(): iterable
    {
        yield 'object' => [new \stdClass(), ''];
        yield 'array' => [['a'], 'Array'];
        yield 'int' => [42, '42'];
        yield 'null' => [null, ''];
        yield 'string' => ['plain', 'plain'];
    }

    #[DataProvider('legacyMessageProvider')]
    public function testNonStringMessageNeitherThrowsNorWarns(mixed $message, string $expected): void
    {
        $error = new LegacyMessageException($message);
        error_clear_last();

        $this->assertSame($expected, ErrorText::message($error));
        $this->assertSame(LegacyMessageException::class . ': ' . $expected, ErrorText::of($error));
        $this->assertNull(error_get_last());
    }

    #[DataProvider('legacyMessageProvider')]
    public function testNonStringMessageUnderAThrowingErrorHandler(mixed $message, string $expected): void
    {
        $error = new LegacyMessageException($message);
        set_error_handler(static fn(int $no, string $str): never => throw new \ErrorException($str, 0, $no));

        try {
            $this->assertSame($expected, ErrorText::message($error));
            $this->assertSame(LegacyMessageException::class . ': ' . $expected, ErrorText::of($error));

            try {
                trigger_error('x', E_USER_WARNING);
                self::fail('the test error handler is no longer installed');
            } catch (\ErrorException $exception) {
                $this->assertSame('x', $exception->getMessage());
            }
        } finally {
            restore_error_handler();
        }
    }
}
