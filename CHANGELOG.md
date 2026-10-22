# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-22

### Added

- `AgentCat::track()` and the `mcp/sdk` adapter: one call on the `Mcp\Server\Builder` tracks every tool of the server.
- The `laravel/mcp` bridge: every server of a Laravel application is tracked, with `config/agentcat.php` and `php artisan agentcat:install`.
- The Symfony bundle, `AgentCat\Symfony\AgentCatBundle`, for `symfony/mcp-bundle` servers.
- The `session_id`, `agent_id` and `context` parameters that AgentCat injects into each tool, and the `get_more_tools` tool.
- The hooks: `identify`, `resolveSessionId`, `eventTags`, `eventProperties`, `redactSensitiveInformation` and `redactEvent`.
- Custom events, with `AgentCat::publishCustomEvent()`.
- The four exporters: OpenTelemetry (OTLP), Datadog, Sentry and PostHog.
- Diagnostics, which send AgentCat the SDK's own log lines to find broken installs, and their opt-out (`disableDiagnostics`, `AGENTCAT_DISABLE_DIAGNOSTICS`).
- Delivery after the response on every runtime: the client has its answer before AgentCat runs the hooks and sends the events.
- `AgentCat::fake()` for your own tests, with `assertTracked()`, `assertNothingTracked()`, `assertTrackedCount()` and `assertCustomEventPublished()`.

### Changed

- AgentCat reads `tools/list` pages until it finds the called tool, so a tool on a later page keeps its own parameters.
  The TypeScript SDK reads only the first page, and for a tool on a later page it guesses which arguments to remove: the guess can remove the tool's own `session_id` or `context` argument.
  Under PHP-FPM and the other runtimes that serve each request in a fresh process, your own `tools/list` handler (one that you add to the server, or a `laravel/mcp` class that answers `tools/list`) then runs once for each page that AgentCat reads during a `tools/call`, so its queries, metrics and log lines repeat that many times.
  A call to the last-page tool of a 300-tool `laravel/mcp` server takes 57.6 ms (2.7 ms before); a call to a page-1 tool costs the same as before.

[Unreleased]: https://github.com/agentcathq/agentcat-php-sdk/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/agentcathq/agentcat-php-sdk/releases/tag/v1.0.0
