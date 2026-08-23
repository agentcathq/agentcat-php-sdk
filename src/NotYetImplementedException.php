<?php

declare(strict_types=1);

namespace AgentCat;

use RuntimeException;

/**
 * Thrown by every entry point until the real SDK ships.
 */
final class NotYetImplementedException extends RuntimeException
{
}
