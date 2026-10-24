<div align="center">
  <img alt="AgentCat — see exactly how agents experience your product" src="https://raw.githubusercontent.com/agentcathq/agentcat-php-sdk/main/docs/static/og-image.png" width="80%">
</div>
<h3 align="center">
    <a href="#getting-started">Getting Started</a>
    <span> · </span>
    <a href="#why-use-agentcat-">Features</a>
    <span> · </span>
    <a href="https://docs.agentcat.com">Docs</a>
    <span> · </span>
    <a href="https://agentcat.com">Website</a>
    <span> · </span>
    <a href="#free-for-open-source">Open Source</a>
    <span> · </span>
    <a href="https://book.agentcat.com/">Schedule a Demo</a>
</h3>
<p align="center">
  <a href="https://packagist.org/packages/agentcat/sdk"><img src="https://img.shields.io/packagist/v/agentcat/sdk.svg" alt="Packagist version"></a>
  <a href="https://packagist.org/packages/agentcat/sdk"><img src="https://img.shields.io/packagist/dm/agentcat/sdk.svg" alt="Packagist downloads"></a>
  <a href="https://opensource.org/licenses/MIT"><img src="https://img.shields.io/badge/License-MIT-blue.svg" alt="License: MIT"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/packagist/dependency-v/agentcat/sdk/php.svg" alt="PHP"></a>
  <a href="https://github.com/agentcathq/agentcat-php-sdk/issues"><img src="https://img.shields.io/github/issues/agentcathq/agentcat-php-sdk.svg" alt="GitHub issues"></a>
  <a href="https://github.com/agentcathq/agentcat-php-sdk/actions/workflows/ci.yml"><img src="https://github.com/agentcathq/agentcat-php-sdk/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

> [!NOTE]
> AgentCat for PHP is compatible with the [MCP Protocol "Stateless" 2026-07-28 Update](https://blog.modelcontextprotocol.io/posts/2026-07-28/) as the PHP libraries put it into effect: [`mcp/sdk`](https://github.com/modelcontextprotocol/php-sdk) over HTTP since 0.8 and over stdio since 0.9.0, and [`laravel/mcp`](https://github.com/laravel/mcp) 1.x. The stateless transition has a massive impact on analytics, as sessions were a built-in concept tying related tool calls together. AgentCat has now migrated its session tracking under guidance of the MCP core team's recommendations of using [explicit handles (SEP-2567)](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2567).
>
> As a result AgentCat now injects a `session_id` on every MCP tool call to associate them under the same task umbrella. Our evals show much higher tool correlation accuracy at the cost of < 1% additional context pollution.

AgentCat is an analytics platform for MCP server owners 🐱. It captures user intentions and behavior patterns to help you understand what AI users actually need from your tools — eliminating guesswork and accelerating product development all with one-line of code.

This SDK also provides a free and simple way to forward telemetry like logs, traces, and errors to any Open Telemetry collector or popular tools like Datadog, Sentry, and PostHog.

```bash
composer require agentcat/sdk
```

To learn more about us, check us out [here](https://agentcat.com). For detailed guides visit our [documentation](https://docs.agentcat.com).

## Why use AgentCat? 🤔

AgentCat helps builders of MCP servers, Claude Connectors, and ChatGPT Plugins learn how to improve them by capturing any agents goals and detecting when they get stuck.

Use AgentCat for:

- **Agent session replay** 🎬. Follow alongside your users and their agents to understand why they're using your MCP servers, what functionality you're missing, and what clients they're coming from.
- **Trace debugging** 🔍. See where your users are getting stuck, track and find when LLMs get confused by your API, and debug sessions across all deployments of your MCP server.
- **Existing platform support** 📊. Get logging and tracing out of the box for your existing observability platforms (OpenTelemetry, Datadog, Sentry) — eliminating the tedious work of implementing telemetry yourself.

<img alt="AgentCat architecture — the AgentCat SDK inside your MCP server sends analytics to your observability vendors and session replay to the AgentCat dashboard" src="https://raw.githubusercontent.com/agentcathq/agentcat-php-sdk/main/docs/static/architecture.png" />

## How it works

AgentCat works as a lightweight middleware inside your MCP server. When you call `track()`, it seamlessly modifies your registered tool schemas in place, following the MCP core team's [explicit handles (SEP-2567)](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2567) guidelines. Concretely, AgentCat adds the following to your server:

- **`session_id`** — a parameter injected into each tool's input schema. Agents echo it back on every call, letting AgentCat group related tool calls into one task even over stateless transports. Values are validated: anything that is not a well-formed AgentCat session ID is rejected rather than adopted, and the agent is told to re-send the ID it was given.
- **`agent_id`** _(off by default)_ — enabled with `enableAgentTracking: true`. Each agent self-generates its own ID, keeping parallel agents working the same task individually attributable.
- **`context`** — a parameter asking the agent to explain, in one sentence, why it is making this call. This is where intent data comes from.
- **`get_more_tools`** — an additional tool, prompt-engineered so that agents readily report the features and tools they looked for but couldn't find — surfacing your missing functionality directly from real usage.

Injected parameters are stripped from arguments before your tool handler runs, so your code never sees them. For tools that declare an output schema, issued IDs are also mirrored into `structuredContent` (as `mcp_session`), so clients that only read structured results still receive them.

## Getting Started

To get started with AgentCat, first create an account and obtain your project ID by signing up at [agentcat.com](https://agentcat.com). For detailed setup instructions visit our [documentation](https://docs.agentcat.com).

Once you have your project ID, set up AgentCat for your MCP library below; the [quickstart](https://docs.agentcat.com/quickstart) walks through each setup step by step. AgentCat stays off while your tests run, and `AgentCat::fake()` records the events of a test for your assertions.

### Plain `mcp/sdk`

Install AgentCat next to `mcp/sdk` (`composer require agentcat/sdk mcp/sdk`), then track the server with one call. Pass the `Mcp\Server\Builder` or the `Mcp\Server` built from it, before or after your tools:

```php
<?php

declare(strict_types=1);

use AgentCat\AgentCat;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

require __DIR__ . '/vendor/autoload.php';

$builder = Server::builder()
    ->setServerInfo('echo-mcp', '0.1.0')
    ->addTool(static fn(string $msg): string => $msg, 'echo', description: 'Echo a message');

// Track the server with AgentCat
AgentCat::track($builder, 'proj_0000000');

$builder->build()->run(new StdioTransport());
```

With `null` instead of the ID, AgentCat reads it from `AGENTCAT_PROJECT_ID`. Under PHP-FPM every request runs your script, so every request builds and tracks a fresh server, and tracking it costs no more than tracking one long-lived server. Within one process, calling `track()` per instance is cheap — the event queue, telemetry exporters, and diagnostics are initialized once and shared across instances. The [`mcp/sdk` guide](https://docs.agentcat.com/sdk/php/mcp-sdk) has the details, HTTP servers included.

### Laravel

Install the package and run its install command, which asks for your project ID and writes it to `.env` as `AGENTCAT_PROJECT_ID`:

```bash
composer require agentcat/sdk
php artisan agentcat:install
```

That is all: every `laravel/mcp` server of the application is tracked, except Laravel Boost's, also when `laravel/mcp` builds the server for each request. To change the options, publish `config/agentcat.php`:

```bash
php artisan vendor:publish --tag=agentcat-config
```

The [Laravel guide](https://docs.agentcat.com/sdk/php/laravel) has the details.

### Symfony

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    AgentCat\Symfony\AgentCatBundle::class => ['all' => true],
];
```

Then set your project ID in `.env.local` (or in the environment of your server), and every `symfony/mcp-bundle` server of the application is tracked:

```dotenv
AGENTCAT_PROJECT_ID=proj_0000000
```

A `config/packages/agentcat.yaml` file is optional: `php bin/console config:dump-reference agentcat` lists the options it can hold. The [Symfony guide](https://docs.agentcat.com/sdk/php/symfony) has the details.

### Identifying users

We strongly encourage identifying every actor. If you can't resolve a real user, return a stable anonymized ID instead — for example, a hash of the auth token or API key — so that all events from the same end user still roll up to one actor in your dashboard rather than scattering into anonymous one-off sessions.

`identify` (like every AgentCat hook) runs after the client has your tool's answer, so it never adds latency to that tool call: a slow lookup — or a hook that fails outright — costs analytics data for that event, never that call's response time.

The callback receives a `ToolCall`: the tool name, the raw arguments and `_meta`, the HTTP headers, the client's name and version, and the request and context objects of your MCP library. Most identity signals live in its `headers`, with lower-case names (empty on stdio):

```php
use AgentCat\AgentCat;
use AgentCat\Identity;
use AgentCat\Options;
use AgentCat\ToolCall;

AgentCat::track($builder, 'proj_0000000', new Options(
    identify: static function (ToolCall $call): ?Identity {
        $token = $call->headers['authorization'] ?? null;
        if ($token === null) {
            return null;
        }

        return new Identity(
            userId: hash('sha256', $token), // a stable anonymized ID, or your own user ID
            userData: ['orgId' => $call->headers['x-org-id'] ?? null],
        );
    },
));
```

In Laravel, the `identify` key of `config/agentcat.php` names an invokable class that receives the same `ToolCall`; in Symfony, the `identify` key of the `agentcat` configuration names an invokable service.

### Redacting sensitive data

AgentCat redacts all data sent to its servers and encrypts at rest, but for additional security, it offers a hook to do your own redaction on all text data returned back to our servers.

```php
use AgentCat\AgentCat;
use AgentCat\Options;

AgentCat::track($builder, 'proj_0000000', new Options(
    redactSensitiveInformation: static fn(string $text): string => preg_replace('/\bsk_live_\w+/', '[REDACTED]', $text) ?? $text,
));
```

For redaction decisions that need more context than a single string — such as which tool was called or what type of event is being published — use the event-level `redactEvent` hook. It receives the full event object and returns a modified event, or `null` to drop the event entirely. It can be combined with `redactSensitiveInformation`.

```php
use AgentCat\AgentCat;
use AgentCat\Event;
use AgentCat\Options;

AgentCat::track($builder, 'proj_0000000', new Options(
    redactEvent: static function (Event $event): ?Event {
        // Drop events from tools that handle secrets entirely
        if ($event->resourceName === 'get_credentials') {
            return null;
        }
        // Strip response payloads from a specific tool
        if ($event->resourceName === 'export_report') {
            $event->response = null;
        }

        return $event;
    },
));
```

### Vendor Support

AgentCat seamlessly integrates with your existing observability stack, providing automatic logging and tracing without the tedious setup typically required. Export telemetry data to multiple platforms simultaneously:

```php
use AgentCat\AgentCat;
use AgentCat\Exporters\DatadogExporter;
use AgentCat\Exporters\OtlpExporter;
use AgentCat\Exporters\PostHogExporter;
use AgentCat\Exporters\SentryExporter;
use AgentCat\Options;

// The project ID can be '' (or null without AGENTCAT_PROJECT_ID) if you just want to forward telemetry
AgentCat::track($builder, 'proj_0000000', new Options(
    exporters: [
        'otlp' => new OtlpExporter(endpoint: 'http://localhost:4318/v1/traces'),
        'datadog' => new DatadogExporter(
            apiKey: (string) getenv('DD_API_KEY'),
            site: 'datadoghq.com',
            service: 'my-mcp-server',
        ),
        'sentry' => new SentryExporter(
            dsn: (string) getenv('SENTRY_DSN'),
            environment: 'production',
        ),
        'posthog' => new PostHogExporter(
            apiKey: (string) getenv('POSTHOG_API_KEY'),
            host: 'https://us.i.posthog.com', // Optional: defaults to US region
        ),
    ],
));
```

Learn more about our free and open source [telemetry integrations](https://docs.agentcat.com/telemetry/integrations).

### Internal diagnostics

To help us catch and fix broken installs, the SDK sends AgentCat every line it logs, with its time and level. The lines can contain session IDs, actor IDs, tool names, error messages and the answers of exporters that failed. Records also carry operational metadata, such as your project ID (or, when none is set, an install ID that hashes the host name and the install path), the OS, and the versions of PHP, the SDK and `mcp/sdk`. Your local log is unchanged: `~/agentcat.log` (or the PSR-3 logger of your `mcp/sdk` Builder) in plain PHP, `storage/logs/agentcat.log` in Laravel and `%kernel.logs_dir%/agentcat.log` in Symfony.

Diagnostics are on by default and can be turned off completely with either:

- `AgentCat::track($builder, $projectId, new Options(disableDiagnostics: true))`, or
- the `AGENTCAT_DISABLE_DIAGNOSTICS` (or `DISABLE_DIAGNOSTICS`) environment variable.

## PHP runtimes

- **PHP-FPM**: AgentCat runs the hooks and delivers the events after `fastcgi_finish_request()`, when the client has its answer.
- **FrankenPHP**: classic mode works like PHP-FPM; in worker mode, a plain `mcp/sdk` worker calls `AgentCat::flush(1.0)` after each request in its `frankenphp_handle_request()` loop, and Laravel Octane and Symfony need nothing.
- **Apache mod_php and NGINX Unit**: the client gets its answer before AgentCat delivers; on mod_php the answer needs a `Content-Length`, which the Laravel and Symfony bridges set for you and which a plain `mcp/sdk` script must set on the answer itself.
- **The CLI**: a stdio server runs the hooks and delivers the events of a call when the next `tools/call` or `tools/list` arrives and when its input ends, so a slow hook delays that next request; AgentCat writes nothing to STDOUT or STDERR, so stdout stays JSON-RPC.
- **Containers**: start a stdio server with `docker run --init`, so that a stop signal ends PHP and AgentCat (with ext-pcntl) first delivers the queued events.
- **Swoole, RoadRunner and Workerman**: with Laravel or Symfony, AgentCat delivers in the framework's terminate hooks (Laravel's `terminating()` callbacks, also under Octane, and Symfony's `kernel.terminate`), waiting at most 1 s after each request; a plain `mcp/sdk` worker calls `AgentCat::flush(1.0)` after each request.

AgentCat supports PHP 8.3, 8.4 and 8.5; `mcp/sdk` 0.8.1 or later; `laravel/mcp` 1.x with Laravel 12.41.1 or later and 13; and `symfony/mcp-bundle` 0.13 and 0.14 with Symfony 7.3 and 8.x. On every pull request and every night, CI reads Packagist and tests the newest patch of each minor release of these three libraries, from the lowest supported version up. The [runtimes guide](https://docs.agentcat.com/sdk/php/runtimes) covers each runtime, containers and hardened hosts, and the [limits page](https://docs.agentcat.com/sdk/php/limits) lists the differences from the TypeScript SDK and the known limits.

## Free for open source

AgentCat is free for qualified open source projects. We believe in supporting the ecosystem that makes MCP possible. If you maintain an open source MCP server, you can access our full analytics platform at no cost.

**How to apply**: Email [hi@agentcat.com](mailto:hi@agentcat.com) with your repository link

_Already using AgentCat? We'll upgrade your account immediately._

## Community Cats 🐱

Meet the cats behind AgentCat! Add your cat to our community by submitting a PR with your cat's photo in the `docs/cats/` directory of the [TypeScript SDK](https://github.com/agentcathq/agentcat-typescript-sdk/tree/main/docs/cats), whose cats this README shows too.

<div align="left">
  <img src="https://raw.githubusercontent.com/agentcathq/agentcat-php-sdk/main/docs/cats/bibi.png" alt="bibi" width="80" height="80">
  <img src="https://raw.githubusercontent.com/agentcathq/agentcat-php-sdk/main/docs/cats/zelda.jpg" alt="zelda" width="80" height="80">
  <img src="https://raw.githubusercontent.com/agentcathq/agentcat-php-sdk/main/docs/cats/void.jpg" alt="void" width="80" height="80">
</div>

_Want to add your cat? Create a PR adding your cat's photo to_ [`docs/cats/` of the TypeScript SDK](https://github.com/agentcathq/agentcat-typescript-sdk/tree/main/docs/cats) _and update its README!_
