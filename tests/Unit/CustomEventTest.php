<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit;

use AgentCat\CustomEvent;
use PHPUnit\Framework\TestCase;

final class CustomEventTest extends TestCase
{
    public function testDefaultsAreAllNull(): void
    {
        $event = new CustomEvent();

        $this->assertNull($event->sessionId);
        $this->assertNull($event->resourceName);
        $this->assertNull($event->parameters);
        $this->assertNull($event->response);
        $this->assertNull($event->message);
        $this->assertNull($event->duration);
        $this->assertNull($event->isError);
        $this->assertNull($event->error);
        $this->assertNull($event->tags);
        $this->assertNull($event->properties);
    }

    public function testNamedArgumentsAreKept(): void
    {
        $error = (object) ['message' => 'Custom error occurred', 'code' => 'ERR_001'];
        $event = new CustomEvent(
            sessionId: 'ses_1',
            resourceName: 'checkout',
            parameters: ['cart_id' => 'c_42'],
            response: ['ok' => true],
            message: 'Checkout completed',
            duration: 12.5,
            isError: true,
            error: $error,
            tags: ['env' => 'prod'],
            properties: ['cart_size' => 3],
        );

        $this->assertSame('ses_1', $event->sessionId);
        $this->assertSame('checkout', $event->resourceName);
        $this->assertSame(['cart_id' => 'c_42'], $event->parameters);
        $this->assertSame(['ok' => true], $event->response);
        $this->assertSame('Checkout completed', $event->message);
        $this->assertEqualsWithDelta(12.5, $event->duration, PHP_FLOAT_EPSILON);
        $this->assertTrue($event->isError);
        $this->assertSame($error, $event->error);
        $this->assertSame(['env' => 'prod'], $event->tags);
        $this->assertSame(['cart_size' => 3], $event->properties);
    }

    public function testClassIsReadonly(): void
    {
        $this->assertTrue((new \ReflectionClass(CustomEvent::class))->isReadOnly());
    }
}
