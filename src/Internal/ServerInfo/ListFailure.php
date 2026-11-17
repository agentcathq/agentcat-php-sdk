<?php

declare(strict_types=1);

namespace AgentCat\Internal\ServerInfo;

/**
 * A list that the heartbeat cannot use, for a reason that AgentCat states itself. Its message goes into a log line,
 * so it never holds text of the customer's code or data: no exception message, no cursor, no JSON-RPC error message.
 *
 * @internal
 */
final class ListFailure extends \RuntimeException {}
