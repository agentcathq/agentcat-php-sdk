<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

use AgentCat\Event;

/**
 * No exporters configured.
 *
 * @internal
 */
final class NullExporterSink implements ExporterSink
{
    #[\Override]
    public function export(Event $event): void {}
}
