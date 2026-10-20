# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `AgentCat::track()` and the `mcp/sdk` adapter: one call on the `Mcp\Server\Builder` tracks every tool of the server.
- The `laravel/mcp` bridge: every server of a Laravel application is tracked, with `config/agentcat.php` and `php artisan agentcat:install`.
- The Symfony bundle, `AgentCat\Symfony\AgentCatBundle`, for `symfony/mcp-bundle` servers, and its Symfony Flex recipe.
- The `session_id`, `agent_id` and `context` parameters that AgentCat injects into each tool, and the `get_more_tools` tool.
- The hooks: `identify`, `resolveSessionId`, `eventTags`, `eventProperties`, `redactSensitiveInformation` and `redactEvent`.
- Custom events, with `AgentCat::publishCustomEvent()`.
- The four exporters: OpenTelemetry (OTLP), Datadog, Sentry and PostHog.
- Diagnostics, which send AgentCat the SDK's own log lines to find broken installs, and their opt-out (`disableDiagnostics`, `AGENTCAT_DISABLE_DIAGNOSTICS`).
- Delivery after the response on every runtime: the client has its answer before AgentCat runs the hooks and sends the events.
- `AgentCat::fake()` for your own tests, with `assertTracked()`, `assertNothingTracked()`, `assertTrackedCount()` and `assertCustomEventPublished()`.

### Changed

- Unlike the TypeScript SDK, AgentCat reads every `tools/list` page, so no listed tool falls back to the heuristic argument strip (B10).
  Under PHP-FPM, a call to the last-page tool of a 300-tool `laravel/mcp` server takes 57.6 ms (2.7 ms before), and a call to a page-1 tool costs the same as before.

[Unreleased]: https://github.com/agentcathq/agentcat-php-sdk/commits/main
