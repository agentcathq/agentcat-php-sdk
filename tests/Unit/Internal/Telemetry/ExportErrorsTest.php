<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Telemetry;

use AgentCat\Internal\Telemetry\ExportErrors;
use AgentCat\Internal\Telemetry\JsError;
use PHPUnit\Framework\TestCase;

final class ExportErrorsTest extends TestCase
{
    public function testAJsErrorKeepsItsJavaScriptName(): void
    {
        $error = new JsError('RangeError', 'too big');

        $this->assertSame('RangeError', $error->name);
        $this->assertSame('too big', $error->getMessage());
        $this->assertSame('RangeError: too big', ExportErrors::text($error));
        $this->assertSame('too big', ExportErrors::message($error));
    }

    public function testAnotherThrowableUsesTheErrorText(): void
    {
        $error = new \InvalidArgumentException('bad');

        $this->assertSame('InvalidArgumentException: bad', ExportErrors::text($error));
        $this->assertSame('bad', ExportErrors::message($error));
    }

    public function testAThrowableWithANonStringMessageNeverThrows(): void
    {
        $error = new class ('x') extends \RuntimeException {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->message = ['not', 'a string']; // @phpstan-ignore assign.propertyType (a legacy subclass can store any value)
            }
        };

        $this->assertSame('Array', ExportErrors::message($error));
        $this->assertStringEndsWith(': Array', ExportErrors::text($error));
    }
}
