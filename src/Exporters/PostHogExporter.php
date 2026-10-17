<?php

declare(strict_types=1);

namespace AgentCat\Exporters;

/**
 * Sends each event to PostHog's batch API as a capture event, plus `$exception` for failed calls. Use it in
 * `Options(exporters: ['posthog' => new PostHogExporter(apiKey: 'phc_…')])`.
 */
final readonly class PostHogExporter
{
    /**
     * @param string      $apiKey          The PostHog project API key (`phc_…`).
     * @param string|null $host            The PostHog host. Default: null, which uses `https://us.i.posthog.com`.
     * @param bool        $enableAITracing Also sends a `$ai_span` per tool call; your tags and properties can replace any `$ai_*` value. Default: false.
     */
    public function __construct(
        public string $apiKey,
        public ?string $host = null,
        public bool $enableAITracing = false,
    ) {}
}
