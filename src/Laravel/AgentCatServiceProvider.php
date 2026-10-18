<?php

declare(strict_types=1);

namespace AgentCat\Laravel;

use AgentCat\AgentCat;
use AgentCat\Client;
use AgentCat\Laravel\Internal\ServerTracker;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Server;

/**
 * Tracks every laravel/mcp server of the application with AgentCat. Laravel discovers it: install the package and set
 * `AGENTCAT_PROJECT_ID`. Publish the configuration with `php artisan vendor:publish --tag=agentcat-config`.
 */
final class AgentCatServiceProvider extends ServiceProvider
{
    private const string CONFIG = __DIR__ . '/../../config/agentcat.php';

    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG, 'agentcat');
        $this->app->singleton(ServerTracker::class);
        $this->app->bind(Client::class, static fn(): Client => AgentCat::client());
    }

    public function boot(): void
    {
        $this->publishes([self::CONFIG => $this->app->configPath('agentcat.php')], 'agentcat-config');
        if (class_exists(Server::class)) {
            $tracker = $this->app->make(ServerTracker::class); // resolved once per worker, never tracks here
            $this->app->afterResolving(Server::class, static function (Server $server, Container $app) use ($tracker): void {
                $tracker->track($server, $app);
            });
        }
    }
}
