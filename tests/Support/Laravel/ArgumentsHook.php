<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Laravel;

/** An invokable class that answers the arguments it got, for any hook of `config/agentcat.php`. */
final class ArgumentsHook
{
    /** @return list<mixed> */
    public function __invoke(mixed ...$arguments): array
    {
        return array_values($arguments);
    }
}
