<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Samples;

/** `(array) new VisibilityThing()` gives the NUL-prefixed keys `"\0*\0count"` and `"\0…VisibilityThing\0secret"`. */
final class VisibilityThing extends ProtectedState
{
    public string $name = 'n';

    private string $secret = 's';

    public function secret(): string
    {
        return $this->secret . $this->count;
    }
}
