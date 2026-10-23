<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal\Access;

use AgentCat\Internal\Access\TouchesPrivateMember;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Server\Handler\Request\CallToolHandler;
use Psr\Log\LoggerInterface;

/**
 * The private members of mcp/sdk's own `tools/call` handler that AgentCat touches (D21): AgentCat makes a copy of the
 * handler with the same members, whose reference handler shows it a failing tool's exception, and, for a server
 * tracked after build(), replaces the schema validator. Each closure is bound to CallToolHandler, so PHPStan checks the
 * member in that class's scope.
 *
 * @internal
 */
final class CallToolHandlerAccess
{
    #[TouchesPrivateMember(class: CallToolHandler::class, member: 'registry', type: RegistryInterface::class, readonly: true)]
    public static function registry(CallToolHandler $handler): RegistryInterface
    {
        $read = \Closure::bind(static fn(CallToolHandler $h): RegistryInterface => $h->registry, null, CallToolHandler::class);

        return $read($handler);
    }

    #[TouchesPrivateMember(class: CallToolHandler::class, member: 'referenceHandler', type: ReferenceHandlerInterface::class, readonly: true)]
    public static function referenceHandler(CallToolHandler $handler): ReferenceHandlerInterface
    {
        $read = \Closure::bind(static fn(CallToolHandler $h): ReferenceHandlerInterface => $h->referenceHandler, null, CallToolHandler::class);

        return $read($handler);
    }

    #[TouchesPrivateMember(class: CallToolHandler::class, member: 'logger', type: LoggerInterface::class, readonly: true)]
    public static function logger(CallToolHandler $handler): LoggerInterface
    {
        $read = \Closure::bind(static fn(CallToolHandler $h): LoggerInterface => $h->logger, null, CallToolHandler::class);

        return $read($handler);
    }

    /** @phpstan-impure */
    #[TouchesPrivateMember(class: CallToolHandler::class, member: 'schemaValidator', type: SchemaValidator::class)]
    public static function schemaValidator(CallToolHandler $handler): SchemaValidator
    {
        $read = \Closure::bind(static fn(CallToolHandler $h): SchemaValidator => $h->schemaValidator, null, CallToolHandler::class);

        return $read($handler);
    }

    #[TouchesPrivateMember(class: CallToolHandler::class, member: 'schemaValidator', type: SchemaValidator::class)]
    public static function setSchemaValidator(CallToolHandler $handler, SchemaValidator $validator): void
    {
        $write = \Closure::bind(static function (CallToolHandler $h) use ($validator): void {
            $h->schemaValidator = $validator;
        }, null, CallToolHandler::class);
        $write($handler);
    }
}
