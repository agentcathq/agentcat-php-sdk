<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Samples;

/** Holds a protected property for subclasses; a final class may not declare one. */
abstract class ProtectedState
{
    protected int $count = 3;
}
