<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Samples;

/** `serialize()` warns for it: `__sleep()` names a property that does not exist. */
final class MissingSleepProperty
{
    /** @return list<string> */
    public function __sleep(): array
    {
        return ['missing'];
    }
}
