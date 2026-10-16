<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Samples;

final class PlainThing
{
    public string $visible = 'v';

    public ?PlainThing $next = null;

    private string $hidden = 'h';

    public function hidden(): string
    {
        return $this->hidden;
    }
}
