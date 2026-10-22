<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Gives a new object the object ID of a freed one, as a long-running process does by chance. PHP hands out the ID
 * that was freed last first: `hold()` takes the ID until `reuse()` frees it right before the new object is created.
 */
final class ReusedId
{
    private function __construct(private ?\stdClass $holder) {}

    /** Takes the ID of a freed object; fails the test when the ID is not free (the object still lives). */
    public static function hold(int $id): self
    {
        $taken = [];
        for ($i = 0; $i < 100_000; ++$i) {
            $holder = new \stdClass();
            if (spl_object_id($holder) === $id) {
                return new self($holder);
            }
            $taken[] = $holder; // keeps every other free ID taken until the search ends
        }
        Assert::fail(sprintf('Object ID %d is not free (%d new objects searched): the object still lives', $id, count($taken)));
    }

    /**
     * Frees the ID, then calls `$make`: the first object it creates gets the ID. Fails the test when the object it
     * returns has another ID.
     *
     * @template T of object
     *
     * @param \Closure(): T $make
     *
     * @return T
     */
    public function reuse(\Closure $make): object
    {
        $id = spl_object_id($this->holder ?? throw new \LogicException('The ID was reused already'));
        $this->holder = null;

        $object = $make();

        Assert::assertSame($id, spl_object_id($object), 'the new object has the ID of the freed one');

        return $object;
    }
}
