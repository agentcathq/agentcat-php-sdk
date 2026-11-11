<?php

declare(strict_types=1);

// Type declarations and instanceof do not autoload aliases, so register the adapter's renamed types up front.
// Composer runs this only after its loader is ready; installations without mcp/sdk define no aliases.
foreach ([
    'Mcp\\Server' => 'Mcp\\Server\\Server',
    'Mcp\\Capability\\Attribute\\CompletionProvider' => 'Mcp\\Server\\Capability\\Attribute\\CompletionProvider',
    'Mcp\\Capability\\Attribute\\McpTool' => 'Mcp\\Server\\Capability\\Attribute\\McpTool',
    'Mcp\\Capability\\Completion\\ProviderInterface' => 'Mcp\\Server\\Capability\\Completion\\ProviderInterface',
    'Mcp\\Capability\\Discovery\\SchemaValidator' => 'Mcp\\Server\\Capability\\Discovery\\SchemaValidator',
    'Mcp\\Capability\\Registry' => 'Mcp\\Server\\Capability\\Registry',
    'Mcp\\Capability\\RegistryInterface' => 'Mcp\\Server\\Capability\\RegistryInterface',
    'Mcp\\Capability\\Registry\\Container' => 'Mcp\\Server\\Capability\\Registry\\Container',
    'Mcp\\Capability\\Registry\\ElementReference' => 'Mcp\\Server\\Capability\\Registry\\ElementReference',
    'Mcp\\Capability\\Registry\\ReferenceHandler' => 'Mcp\\Server\\Capability\\Registry\\ReferenceHandler',
    'Mcp\\Capability\\Registry\\ReferenceHandlerInterface' => 'Mcp\\Server\\Capability\\Registry\\ReferenceHandlerInterface',
    'Mcp\\Capability\\Registry\\ToolReference' => 'Mcp\\Server\\Capability\\Registry\\ToolReference',
    'Mcp\\Event\\ResponseEvent' => 'Mcp\\Server\\Event\\ResponseEvent',
    'Mcp\\Exception\\ToolCallException' => 'Mcp\\Server\\Exception\\ToolCallException',
    'Mcp\\Server\\Stateless\\RequestMeta' => 'Mcp\\Schema\\RequestMeta',
] as $alias => $class) {
    if (!class_exists($alias) && !interface_exists($alias) && (class_exists($class) || interface_exists($class))) {
        class_alias($class, $alias);
    }
}
unset($alias, $class);
