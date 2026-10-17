<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Support;

use AgentCat\Internal\Support\StrictErrors;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StrictErrorsTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function levelProvider(): iterable
    {
        yield 'warning' => [E_USER_WARNING];
        yield 'notice' => [E_USER_NOTICE];
        yield 'deprecation' => [E_USER_DEPRECATED];
    }

    #[DataProvider('levelProvider')]
    public function testAnErrorBecomesAnErrorException(int $level): void
    {
        $error = Thrown::by(\ErrorException::class, static fn(): bool => StrictErrors::run(static fn(): bool => trigger_error('boom', $level)));
        $line = __LINE__ - 1;

        $this->assertSame('boom', $error->getMessage());
        $this->assertSame($level, $error->getSeverity());
        $this->assertSame(0, $error->getCode());
        $this->assertSame(__FILE__, $error->getFile());
        $this->assertSame($line, $error->getLine());
    }

    public function testAPhpWarningBecomesAnErrorException(): void
    {
        $error = Thrown::by(\ErrorException::class, static fn(): mixed => StrictErrors::run(static fn(): string|false => hex2bin('odd')));

        $this->assertStringStartsWith('hex2bin(): ', $error->getMessage());
        $this->assertSame(E_WARNING, $error->getSeverity());
    }

    public function testReturnsTheValueAndRestoresThePreviousHandler(): void
    {
        $previous = static fn(): bool => false;
        set_error_handler($previous);

        try {
            $value = new \stdClass();
            $this->assertSame($value, StrictErrors::run(static fn(): \stdClass => $value));
            $this->assertSame($previous, $this->activeHandler());

            Thrown::by(\ErrorException::class, static fn(): bool => StrictErrors::run(static fn(): bool => trigger_error('boom', E_USER_WARNING)));
            $this->assertSame($previous, $this->activeHandler());
        } finally {
            restore_error_handler();
        }
    }

    /** Reads the active error handler and leaves the handler stack as it was. */
    private function activeHandler(): mixed
    {
        $handler = set_error_handler(null);
        restore_error_handler();

        return $handler;
    }
}
