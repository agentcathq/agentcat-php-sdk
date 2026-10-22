<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Laravel;

use AgentCat\Tests\Support\SrcErrorGuard;
use Illuminate\Support\ServiceProvider;

/** Records whether the guard is the error handler in place while a provider registers and boots, as AgentCat's does. */
final class GuardPositionProvider extends ServiceProvider
{
    /** @var array<string, bool> `register` and `boot`: whether the guard was in front */
    public static array $inFront = [];

    #[\Override]
    public function register(): void
    {
        self::$inFront['register'] = SrcErrorGuard::inFront();
    }

    public function boot(): void
    {
        self::$inFront['boot'] = SrcErrorGuard::inFront();
    }
}
