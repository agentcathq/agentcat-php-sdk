<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Handles;

use AgentCat\Internal\Handles\ArgumentStripper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ArgumentStripperTest extends TestCase
{
    private function full(): \stdClass
    {
        return (object) ['text' => 'hi', 'session_id' => 's', 'agent_id' => 'a', 'context' => 'c'];
    }

    private function assertObject(mixed $v): \stdClass
    {
        $this->assertInstanceOf(\stdClass::class, $v);

        return $v;
    }

    #[Group('ac-04-29')]
    public function testRegistryNamesAreRemovedAndInputUntouched(): void
    {
        $args = $this->full();
        $out = ArgumentStripper::strip($args, 'echo', ['echo' => ['session_id', 'agent_id', 'context']]);
        $this->assertEquals((object) ['text' => 'hi'], $out);
        $this->assertNotSame($args, $out);
        $this->assertCount(4, get_object_vars($args));
    }

    #[Group('ac-04-30')]
    public function testHeuristicWithoutRegistry(): void
    {
        $this->assertEquals((object) ['text' => 'hi'], ArgumentStripper::strip($this->full(), 'echo', null));
        $this->assertEquals(
            (object) ['text' => 'hi', 'context' => 'c'],
            ArgumentStripper::strip($this->full(), 'get_more_tools', null),
        );
    }

    #[Group('ac-04-30')]
    public function testHeuristicWhenRegistryHasNoEntry(): void
    {
        $this->assertEquals((object) ['text' => 'hi'], ArgumentStripper::strip($this->full(), 'echo', ['other' => []]));
        $this->assertEquals(
            (object) ['text' => 'hi', 'context' => 'c'],
            ArgumentStripper::strip($this->full(), 'get_more_tools', ['other' => []]),
        );
    }

    #[Group('ac-04-30')]
    public function testEmptyRegistryEntryStripsNothing(): void
    {
        $this->assertEquals($this->full(), ArgumentStripper::strip($this->full(), 'echo', ['echo' => []]));
    }

    #[Group('ac-04-30')]
    public function testCustomerOwnedNameIsKept(): void
    {
        $out = ArgumentStripper::strip($this->full(), 'echo', ['echo' => ['agent_id', 'context']]);
        $this->assertEquals((object) ['text' => 'hi', 'session_id' => 's'], $out);
    }

    #[Group('ac-04-30')]
    public function testNumericToolNameFindsIntKey(): void
    {
        $registry = [0 => []];
        $this->assertEquals($this->full(), ArgumentStripper::strip($this->full(), '0', $registry));
        $registry = [0 => ['context']];
        $this->assertEquals(
            (object) ['text' => 'hi', 'session_id' => 's', 'agent_id' => 'a'],
            ArgumentStripper::strip($this->full(), '0', $registry),
        );
    }

    #[Group('ac-04-31')]
    public function testKeyOrderIsPreserved(): void
    {
        $args = (object) ['z' => 1, 'session_id' => 's', 'a' => 2, 'context' => 'c', 'm' => 3];
        $out = ArgumentStripper::strip($args, 'echo', null);
        $this->assertSame(['z', 'a', 'm'], array_keys(get_object_vars($this->assertObject($out))));
    }

    #[Group('ac-04-31')]
    public function testNumericKeysSurvive(): void
    {
        $args = (object) ['0' => 'x', 'session_id' => 's'];
        $out = ArgumentStripper::strip($args, 'echo', null);
        $this->assertSame('{"0":"x"}', json_encode($out));
    }

    #[Group('ac-04-31')]
    public function testNonObjectsReturnedAsIs(): void
    {
        $list = ['session_id' => 's'];
        $this->assertSame($list, ArgumentStripper::strip($list, 'echo', null));
        $this->assertNull(ArgumentStripper::strip(null, 'echo', null));
        $this->assertSame('x', ArgumentStripper::strip('x', 'echo', null));
    }
}
