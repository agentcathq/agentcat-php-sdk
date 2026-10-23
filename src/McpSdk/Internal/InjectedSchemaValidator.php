<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use Mcp\Capability\Discovery\SchemaValidator;
use Psr\Log\LoggerInterface;

/**
 * The schema validator of the library's `tools/call` handler on a server tracked after build(). That server advertises
 * AgentCat's parameters in each tool's input schema, and some are required, so a call that leaves them out would fail
 * mcp/sdk's validation before AgentCat could issue a session. For a schema that AgentCat injected, this validator
 * removes the injected arguments and checks the rest against the tool's own schema, as the library does for a Builder
 * tracked before build(); the errors and the log lines are the same. `get_more_tools` is answered by AgentCat and is
 * not checked. Any other schema is checked unchanged.
 *
 * @internal
 */
final class InjectedSchemaValidator extends SchemaValidator
{
    /**
     * @param LoggerInterface $logger the logger of the library's `tools/call` handler, which gave it to its validator
     * @param list<array{array<string, mixed>, array<string, mixed>|null, array<array-key>}> $injected each injected schema,
     *        the tool's own schema (null: AgentCat answers the tool) and the names of the arguments AgentCat injected
     */
    public function __construct(
        LoggerInterface $logger,
        private readonly array $injected,
    ) {
        parent::__construct(logger: $logger); // named: mcp/sdk 0.9 puts a validator first
    }

    /**
     * @param array<string, mixed>|object $schema
     *
     * @return list<array{pointer: string, keyword: string, message: string}>
     */
    #[\Override]
    public function validateAgainstJsonSchema(mixed $data, array|object $schema): array
    {
        foreach ($this->injected as [$injected, $original, $names]) {
            if ($schema !== $injected) {
                continue;
            }
            if ($original === null) {
                return [];
            }
            if (is_array($data)) {
                foreach ($names as $name) {
                    unset($data[$name]);
                }
                // The library's handler passes no arguments as `{}`, which a JSON Schema of type object accepts.
                $data = $data === [] ? new \stdClass() : $data;
            }

            return parent::validateAgainstJsonSchema($data, $original);
        }

        return parent::validateAgainstJsonSchema($data, $schema);
    }
}
