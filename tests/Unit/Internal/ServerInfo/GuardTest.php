<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\ServerInfo;

use AgentCat\Internal\ServerInfo\Guard;
use PHPUnit\Framework\TestCase;

final class GuardTest extends TestCase
{
    private const string PROJECT = 'proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa';

    #[\Override]
    protected function setUp(): void
    {
        Guard::forget();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Guard::forget();
    }

    public function testV19AToolCallOpensTheGuardOnlyInAMemoryOfSixtySeconds(): void
    {
        $this->assertFalse(Guard::open(false, 1000.0), 'the first check: an age of 0 s');
        $this->assertFalse(Guard::open(false, 1059.0));
        $this->assertFalse(Guard::open(false, 1059.999));
        $this->assertTrue(Guard::open(false, 1060.0));
        $this->assertTrue(Guard::open(false, 5000.0));
    }

    public function testV17AToolListOrAFeedbackNoteOpensTheGuardInANewMemory(): void
    {
        $this->assertTrue(Guard::open(true, 1000.0));
        $this->assertFalse(Guard::open(false, 1001.0), 'the first check fixed the age, whatever opened the guard');
        $this->assertTrue(Guard::open(false, 1060.0));
    }

    public function testV7AnAttemptHoldsItsKeyForOneHour(): void
    {
        $this->assertTrue(Guard::claim(self::PROJECT, 'demo-server', 0.0));
        $this->assertFalse(Guard::claim(self::PROJECT, 'demo-server', 0.0));
        $this->assertFalse(Guard::claim(self::PROJECT, 'demo-server', 3599.0));
        $this->assertFalse(Guard::claim(self::PROJECT, 'demo-server', 3599.999));
        $this->assertTrue(Guard::claim(self::PROJECT, 'demo-server', 3600.0));
        $this->assertFalse(Guard::claim(self::PROJECT, 'demo-server', 7199.0), 'the hour counts from the last attempt');
        $this->assertTrue(Guard::claim(self::PROJECT, 'demo-server', 7200.0));
    }

    public function testV8TheKeyIsTheProjectAndTheServerName(): void
    {
        $this->assertTrue(Guard::claim(self::PROJECT, 'demo-server', 0.0));
        $this->assertTrue(Guard::claim(self::PROJECT, 'other-server', 0.0));
        $this->assertTrue(Guard::claim('proj_bbbbbbbbbbbbbbbbbbbbbbbbbbb', 'demo-server', 0.0));
        $this->assertFalse(Guard::claim(self::PROJECT, 'other-server', 1.0));
        $this->assertTrue(Guard::claim('a', "b\0c", 0.0));
        $this->assertTrue(Guard::claim("a\0b", 'c', 0.0), 'the two parts never run together');
    }

    public function testAFullGuardIsEmptiedBeforeTheNextKey(): void
    {
        for ($i = 0; $i < Guard::MAX_KEYS; ++$i) {
            $this->assertTrue(Guard::claim(self::PROJECT, 'server-' . $i, 0.0));
        }
        $this->assertFalse(Guard::claim(self::PROJECT, 'server-0', 1.0), 'a full guard still holds its keys');
        $this->assertFalse(Guard::claim(self::PROJECT, 'server-999', 1.0));

        $this->assertTrue(Guard::claim(self::PROJECT, 'one-more', 1.0));

        $this->assertTrue(Guard::claim(self::PROJECT, 'server-0', 2.0), 'the guard was emptied');
        $this->assertFalse(Guard::claim(self::PROJECT, 'one-more', 2.0), 'and holds the new key');
    }

    public function testForgetEmptiesTheMemory(): void
    {
        Guard::open(true, 0.0);
        Guard::claim(self::PROJECT, 'demo-server', 0.0);

        Guard::forget();

        $this->assertFalse(Guard::open(false, 100.0), 'the age starts again');
        $this->assertTrue(Guard::claim(self::PROJECT, 'demo-server', 100.0));
    }
}
