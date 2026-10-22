## AgentCat (agentcat/sdk)

AgentCat is analytics for the MCP servers of this application. It records one event for each tool call of every `laravel/mcp` server, with the reason the agent gives for the call, and sends the events to an AgentCat project.

### Install

- Install the package with `composer require agentcat/sdk`. Laravel discovers its service provider.
- Run `php artisan agentcat:install`. It asks for the project ID (`proj_` and 27 letters or digits) and writes it to `.env` as `AGENTCAT_PROJECT_ID`. `php artisan agentcat:install proj_… --config` takes the ID as an argument and also publishes `config/agentcat.php`.
- Without the command, set `AGENTCAT_PROJECT_ID` in `.env`. Without a project ID, no event goes to AgentCat.

### What AgentCat tracks with no code

- Every `laravel/mcp` server of the application, also when `laravel/mcp` builds the server for each request. The server of Laravel Boost is excluded.
- Every tool call. AgentCat adds a `session_id` parameter to the input schema of each tool, a `context` parameter unless `enable_tool_call_context` is false, and `agent_id` when `enable_agent_tracking` is true. It removes them from the arguments before the tool runs. Do not declare or read these parameters in a tool.
- A `get_more_tools` tool, which agents call to report a tool that they looked for and did not find. Do not give a tool of your own this name.
- AgentCat runs its hooks and sends the events after the client has the answer of the call (over HTTP, in the `terminating()` callbacks of Laravel), so it adds no latency to that call.

### Configuration

Publish `config/agentcat.php` with `php artisan vendor:publish --tag=agentcat-config`. The keys for hooks and exclusion:

- `exclude`: server classes that AgentCat never tracks. A class also excludes its subclasses.
- `projects`: another project for some servers, as server class => project ID.
- `enabled`: true or false for every server (`AGENTCAT_ENABLED`). Unset: on, except while tests run.
- `identify`, `resolve_session_id`, `event_tags`, `event_properties`, `redact_sensitive_information` and `redact_event`: the hooks. Each hook is the class name of an invokable class, which the container resolves when the hook runs. A closure cannot go in this file, because `php artisan config:cache` cannot store it.

An `identify` hook receives the `AgentCat\ToolCall` (tool name, arguments, lower-case HTTP headers, client name and version) and returns who made the call, or null:

```php
namespace App\AgentCat;

use AgentCat\Identity;
use AgentCat\ToolCall;

final class IdentifyActor
{
    public function __invoke(ToolCall $call): ?Identity
    {
        $token = $call->headers['authorization'] ?? null;

        return $token === null ? null : new Identity(userId: hash('sha256', $token));
    }
}
```

In `config/agentcat.php`: `'identify' => App\AgentCat\IdentifyActor::class,`.

### Tests

AgentCat is off while tests run. `AgentCat::fake()` records the events of a test and sends nothing. Call it before the test builds a server, and restore the real client with `AgentCat::setClient(null)`:

```php
use AgentCat\AgentCat;
use AgentCat\Event;
use App\Mcp\Servers\WeatherServer;
use App\Mcp\Tools\CurrentWeatherTool;

public function test_the_weather_tool_call_is_tracked(): void
{
    $fake = AgentCat::fake();

    WeatherServer::tool(CurrentWeatherTool::class, ['location' => 'Paris'])->assertHasNoErrors();

    $fake->assertTrackedCount(1);
    $fake->assertTracked(fn (Event $event): bool => $event->isError !== true);
}

protected function tearDown(): void
{
    AgentCat::setClient(null);
    parent::tearDown();
}
```

The fake also has `assertNothingTracked()`, `assertCustomEventPublished()`, `events()` and `logs()` (the lines AgentCat logged, hook errors included).

The Laravel guide has the details: https://docs.agentcat.com/sdk/php/laravel
