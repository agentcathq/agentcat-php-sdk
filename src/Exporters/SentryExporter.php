<?php

declare(strict_types=1);

namespace AgentCat\Exporters;

/**
 * Sends each event to Sentry as a log, optionally a transaction, and an error event for failed calls. Use it in
 * `Options(exporters: ['sentry' => new SentryExporter(dsn: '…')])`.
 */
final readonly class SentryExporter
{
    /**
     * @param string      $dsn           Your project's DSN, `https://<public key>@<host>/<project ID>`. An invalid DSN logs one line and the exporter is skipped.
     * @param string|null $environment   Added as the `environment` tag of transactions and error events. Default: null (no tag).
     * @param string|null $release       Added as the `release` tag of transactions and error events. Default: null (no tag).
     * @param bool        $enableTracing Also sends one transaction per event. Default: false.
     */
    public function __construct(
        public string $dsn,
        public ?string $environment = null,
        public ?string $release = null,
        public bool $enableTracing = false,
    ) {}
}
