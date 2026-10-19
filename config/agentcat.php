<?php

declare(strict_types=1);

// AgentCat analytics for every laravel/mcp server of this application.
// Publish this file with: php artisan vendor:publish --tag=agentcat-config

return [
    // On or off for every MCP server and every AgentCat call: true or false. Unset: on, except while your tests run.
    'enabled' => env('AGENTCAT_ENABLED'),

    // Your AgentCat project ID (proj_…). Unset: no events go to AgentCat; exporters still run.
    'project_id' => env('AGENTCAT_PROJECT_ID'),

    // Servers AgentCat never tracks: server classes. A class also excludes its subclasses.
    'exclude' => [
        'Laravel\Boost\Mcp\Boost',
    ],

    // Another project for some servers: server class => project ID. The first class the server is an instance of wins.
    'projects' => [
        // App\Mcp\Servers\BillingServer::class => env('AGENTCAT_BILLING_PROJECT_ID'),
    ],

    // The log channel for AgentCat's own lines. Unset: the application's default channel.
    'log_channel' => env('AGENTCAT_LOG_CHANNEL'),

    // The AgentCat API. Unset: AgentCat's. MCPCAT_API_URL is the TypeScript SDK's name.
    'api_url' => env('AGENTCAT_API_URL', env('MCPCAT_API_URL')),

    'enable_report_missing' => true,
    'enable_tracing' => true,
    'enable_tool_call_context' => true,
    'custom_context_description' => null,
    'enable_agent_tracking' => false,

    // Hooks: the class name of an invokable class, resolved from the container when the hook runs.
    // Closures cannot go here: php artisan config:cache cannot store them.
    'identify' => null,
    'resolve_session_id' => null,
    'event_tags' => null,
    'event_properties' => null,
    'redact_sensitive_information' => null,
    'redact_event' => null,

    // Your own telemetry tools: name => ['type' => 'otlp'|'datadog'|'sentry'|'posthog', …]. A null entry is skipped.
    'exporters' => null,

    // Diagnostics: AgentCat's own log lines, sent to AgentCat so that it can find broken installs. true turns them off;
    // in a list, one true value is enough. DISABLE_DIAGNOSTICS is the TypeScript SDK's name.
    'disable_diagnostics' => [env('AGENTCAT_DISABLE_DIAGNOSTICS'), env('DISABLE_DIAGNOSTICS')],

    // Where diagnostics go: an OpenTelemetry collector (OTLP/HTTP logs) and its bearer token. Unset: AgentCat's.
    'diagnostics_endpoint' => env('AGENTCAT_DIAGNOSTICS_ENDPOINT'),
    'diagnostics_token' => env('AGENTCAT_DIAGNOSTICS_TOKEN'),

    'disable_signal_handlers' => false,
];
