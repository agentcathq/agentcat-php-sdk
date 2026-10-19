<?php

declare(strict_types=1);

namespace AgentCat\Laravel;

use AgentCat\AgentCat;
use AgentCat\Client;
use AgentCat\Internal\Support\BridgeConfig;
use AgentCat\Internal\Support\ConfigOptions;
use AgentCat\Internal\Support\TestMode;
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
        // `agentcat.enabled` decides for every client, the shared one too, before AGENTCAT_ENABLED, which a cached
        // configuration hides (header conflict 12). Unset or unknown: no switch. A fake still records.
        $config = $this->app->make('config');
        TestMode::configure(TestMode::word($config->get('agentcat.enabled')));
        // The same for every other name AgentCat reads, and diagnostics get the application's environment. The
        // tracker logs the bad values; nothing is logged here.
        BridgeConfig::configure([
            'AGENTCAT_PROJECT_ID' => $this->string($config->get('agentcat.project_id')),
            'AGENTCAT_API_URL' => $this->string($config->get('agentcat.api_url')),
            'AGENTCAT_DIAGNOSTICS_ENDPOINT' => $this->string($config->get('agentcat.diagnostics_endpoint')),
            'AGENTCAT_DIAGNOSTICS_TOKEN' => $this->string($config->get('agentcat.diagnostics_token')),
            'APP_ENV' => $this->string($config->get('app.env')),
        ], ConfigOptions::diagnosticsOff($config->get('agentcat.disable_diagnostics')));
        if (class_exists(Server::class)) {
            $tracker = $this->app->make(ServerTracker::class); // resolved once per worker, never tracks here
            $this->app->afterResolving(Server::class, static function (Server $server, Container $app) use ($tracker): void {
                $tracker->track($server, $app);
            });
            AnswerLength::register($this->app); // D59: only where a response ends with the script
        }
        AfterRequest::register($this->app); // callbacks only: nothing runs at boot
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
