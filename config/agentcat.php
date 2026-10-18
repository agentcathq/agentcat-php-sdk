<?php

declare(strict_types=1);

// AgentCat analytics for every laravel/mcp server of this application.
// Publish this file with: php artisan vendor:publish --tag=agentcat-config

return [
    // On or off for every MCP server: true or false. Unset: on, except while your tests run.
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

    'api_url' => env('AGENTCAT_API_URL'),

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

    'disable_diagnostics' => env('AGENTCAT_DISABLE_DIAGNOSTICS'),
    'disable_signal_handlers' => false,
];
