<?php

declare(strict_types=1);

use AgentCat\Laravel\AgentCatServiceProvider;
use AgentCat\Tests\Support\Laravel\SleepingIdentify;
use AgentCat\Tests\Support\LaravelMcp\ConformanceServer;
use AgentCat\Tests\Support\LaravelMcp\CustomCallServer;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\Foundation\Application;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// A booted Laravel application with laravel/mcp, one web and two local servers, and AgentCat only as the service
// provider that package discovery registers from composer.json (ServiceProviderTest checks that entry). Every setting
// comes from the environment the test passes and, under php -S, from the request's X-E2E-* headers.
//
// The application lives in APP_BASE_PATH, a directory under the test HOME: its caches, storage and environment file
// are there, so nothing is written into the Testbench skeleton in vendor/. LARAVEL_STORAGE_PATH cannot do this under
// php -S: Laravel reads it from $_ENV and $_SERVER only; the built-in server does not copy the environment into
// $_SERVER, and $_ENV stays empty with php.ini's usual variables_order=GPCS.
$base = getenv('APP_BASE_PATH');
if (!is_string($base) || $base === '') {
    throw new RuntimeException('APP_BASE_PATH must name the application directory under the test HOME');
}
// The same application without AgentCat, for the answers an excluded server must keep.
$providers = getenv('AGENTCAT_E2E_NO_PROVIDER') === '1' ? [McpServiceProvider::class] : [McpServiceProvider::class, AgentCatServiceProvider::class];

return Application::create(
    basePath: $base,
    resolvingCallback: static function (LaravelApplication $app): void {
        $app->booted(static function () use ($app): void {
            $home = (string) getenv('HOME');
            $config = $app->make('config');
            $config->set('logging.channels.e2e', ['driver' => 'single', 'path' => $home . '/laravel.log']);
            $config->set('agentcat.log_channel', 'e2e');
            $config->set('app.debug', false);
            // Laravel Boost's pattern: a local server with its own tools/call handler, excluded in config/agentcat.php.
            $config->push('agentcat.exclude', CustomCallServer::class);
            $collector = $_SERVER['HTTP_X_E2E_COLLECTOR'] ?? null;
            if (is_string($collector)) {
                $config->set('agentcat.api_url', $collector);
            }
            if (($_SERVER['HTTP_X_E2E_IDENTIFY_SLEEP'] ?? null) === '1') {
                $config->set('agentcat.identify', SleepingIdentify::class);
            }
            if (($_SERVER['HTTP_X_E2E_CONTENT_LENGTH'] ?? null) === '1') {
                // A customer's listener that sends the length of each answer: laravel/mcp sends none.
                $app->make('events')->listen(RequestHandled::class, static function (RequestHandled $handled): void {
                    $content = $handled->response->getContent();
                    if (is_string($content)) {
                        $handled->response->headers->set('Content-Length', (string) strlen($content));
                    }
                });
            }
            Mcp::web('/mcp/e2e', ConformanceServer::class);
            Mcp::local('e2e', ConformanceServer::class);
            Mcp::local('boost-style', CustomCallServer::class);
        });
    },
    options: ['extra' => ['providers' => $providers, 'dont-discover' => ['*']]],
);
