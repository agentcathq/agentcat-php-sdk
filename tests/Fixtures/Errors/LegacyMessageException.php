<?php

declare(strict_types=1);

namespace AgentCat\Tests\Fixtures\Errors;

/** A legacy exception that stores a non-string `$message`, as some old libraries do. */
final class LegacyMessageException extends \RuntimeException
{
    public function __construct(mixed $message, ?\Throwable $previous = null)
    {
        parent::__construct('', 0, $previous);
        // @phpstan-ignore assign.propertyType (simulates a legacy subclass that stores a non-string message)
        $this->message = $message;
    }
}
