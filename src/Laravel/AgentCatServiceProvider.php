<?php

declare(strict_types=1);

namespace AgentCat\Laravel;

use AgentCat\AgentCat;
use AgentCat\Client;
use AgentCat\Internal\Support\BridgeConfig;
use AgentCat\Laravel\Console\InstallCommand;
use AgentCat\Laravel\Internal\AfterRequest;
use AgentCat\Laravel\Internal\AnswerLength;
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
        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }
        // `agentcat.enabled` and every other name AgentCat reads decide for every client, the shared one too, before
        // the environment, which a cached configuration hides (header conflict 12). A fake still records.
        $config = $this->app->make('config');
        $agentcat = $config->get('agentcat');
        BridgeConfig::fromBridge(is_array($agentcat) ? $agentcat : [], $config->get('app.env'));
        if (class_exists(Server::class)) {
            $tracker = $this->app->make(ServerTracker::class); // resolved once per worker, never tracks here
            $this->app->afterResolving(Server::class, static function (Server $server, Container $app) use ($tracker): void {
                $tracker->track($server, $app);
            });
            AnswerLength::register($this->app); // D59: only where a response ends with the script
        }
        AfterRequest::register($this->app); // callbacks only: nothing runs at boot
    }
}
