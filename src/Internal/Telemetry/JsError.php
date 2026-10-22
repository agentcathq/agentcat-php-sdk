<?php

declare(strict_types=1);

namespace AgentCat\Internal\Telemetry;

/**
 * An error the TypeScript SDK builds itself, with its JavaScript error name (`RangeError`, `TypeError`, `Error`).
 *
 * @internal
 */
final class JsError extends \RuntimeException
{
    public function __construct(
        public readonly string $name,
        string $message,
    ) {
        parent::__construct($message);
    }
}
