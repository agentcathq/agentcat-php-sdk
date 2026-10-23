<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\ThrowableStash;
use AgentCat\Internal\Engine\ToolListing;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Json\JsonTree;
use AgentCat\Internal\Tools\GetMoreTools;
use AgentCat\McpSdk\Internal\Access\BuilderAccess;
use AgentCat\McpSdk\Internal\Access\CallToolHandlerAccess;
use AgentCat\McpSdk\Internal\Access\RegistryAccess;
use AgentCat\McpSdk\Internal\Access\ServerAccess;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Implementation;
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\InitializeHandler;
use Mcp\Server\Handler\Request\ListToolsHandler;
use Psr\Log\NullLogger;

/**
 * Tracking a server after build() (D76): a Builder that was built, or the `Mcp\Server` built from a Builder. Its
 * request handlers are fixed in `readonly` members, so AgentCat cannot answer before them. It changes the two objects
 * they share, which stay mutable: the tool registry, whose tools AgentCat replaces with tools that advertise its
 * parameters and run through AgentCat (`RegistryCall`), and the schema validator of the library's `tools/call` handler
 * (`InjectedSchemaValidator`). The registry is loaded first, as its first read would load it.
 *
 * What such a server cannot give an event for: a call to a tool name the registry does not know, a call whose
 * arguments fail the tool's schema, and a call that a `tools/call` handler of the customer's answers. The library
 * answers those before any tool runs. A server with a reference handler of the customer's is not tracked after
 * build(): AgentCat's tool handler would run inside it.
 *
 * @phpstan-import-type ToolData from Tool
 *
 * @internal
 */
final class BuiltServer
{
    /** @var \WeakMap<Registry, TrackedServer>|null the registries of the servers tracked after build() */
    private static ?\WeakMap $tracked = null;

    /** True for a Builder that build() assembled and for a built server: the objects this class tracks. */
    public static function applies(object $server): bool
    {
        return $server instanceof Server || ($server instanceof Builder && BuilderAccess::isBuilt($server));
    }

    /**
     * The registry and the library's `tools/call` handler of a built server, read through its private members.
     *
     * @return array{Registry, CallToolHandler}
     *
     * @throws \UnexpectedValueException when the server holds something that AgentCat cannot track
     */
    public static function parts(Builder|Server $server): array
    {
        [$library, $registry] = self::find($server);
        if (!$library instanceof CallToolHandler) {
            throw new \UnexpectedValueException('the mcp/sdk server has no tools/call handler of its own.');
        }
        if (!$registry instanceof Registry) {
            throw new \UnexpectedValueException(sprintf('the mcp/sdk server has a registry of its own (%s).', get_debug_type($registry)));
        }
        $reference = CallToolHandlerAccess::referenceHandler($library);
        if (!$reference instanceof ReferenceHandler) {
            // AgentCat's tools run inside the reference handler: one of the customer's could run each tool twice, or
            // pass AgentCat's handler no request. Before build(), AgentCat runs it once, around the tool.
            throw new \UnexpectedValueException(sprintf('the mcp/sdk server has a reference handler of its own (%s): track the Builder before build().', get_debug_type($reference)));
        }

        return [$registry, $library];
    }

    /** The name and version of a built server, from its first `initialize` handler. */
    public static function serverInfo(Server $server): ?Implementation
    {
        foreach (ServerAccess::requestHandlers(ServerAccess::protocol($server)) as $handler) {
            if ($handler instanceof InitializeHandler) {
                return $handler->configuration?->serverInfo;
            }
        }

        return null;
    }

    /** The tracking state of a Builder or server tracked after build(): its registry holds it. */
    public static function trackedServer(Builder|Server $server): ?TrackedServer
    {
        [, $registry] = self::find($server);

        return $registry instanceof Registry ? self::$tracked[$registry] ?? null : null;
    }

    /**
     * Every read and every object comes before the first change, so a failure leaves the server untouched.
     *
     * @throws \Throwable when a member cannot be read or a tool cannot be advertised
     */
    public static function install(Registry $registry, CallToolHandler $library, TrackedServer $server, Publisher $publisher, CallInterceptor $interceptor): void
    {
        $registry->load();
        $tools = [];
        $trees = [];
        foreach (RegistryAccess::tools($registry) as $name => $reference) {
            if (!is_string($name) || !$reference instanceof ToolReference) {
                throw new \UnexpectedValueException(sprintf('the mcp/sdk registry holds a tool AgentCat cannot read (%s).', get_debug_type($reference)));
            }
            $tools[$name] = $reference;
            $trees[] = JsonTree::of($reference->tool);
        }
        $validator = CallToolHandlerAccess::schemaValidator($library);
        $original = new Registry();
        RegistryAccess::setTools($original, $tools);
        $stash = new ThrowableStash();
        $handler = (new RegistryCall(
            $server,
            $interceptor,
            $publisher,
            // The tools as registered, run as the library would; the library's handler logs their failures.
            new CallToolHandler($original, new CapturingReferenceHandler(CallToolHandlerAccess::referenceHandler($library), $stash), new NullLogger(), $validator),
            new ListToolsHandler($original),
            $stash,
            new RequestReader($server->httpContext),
        ))->handler();
        $advertised = [];
        foreach (ToolListing::apply($server, $trees, true) ?? [] as $tree) {
            $tool = self::tool($tree);
            $advertised[$tool->name] = $tool;
        }

        // Each tool keeps its place; one that a degraded injection did not advertise keeps its own schema.
        $replaced = [];
        $schemas = [];
        foreach ($tools as $name => $reference) {
            $tool = $advertised[$name] ?? $reference->tool;
            $replaced[$name] = new ToolReference($tool, $handler);
            $answered = $name === GetMoreTools::NAME && $server->options->enableReportMissing; // AgentCat answers it
            $schemas[] = [$tool->inputSchema, $answered ? null : $reference->tool->inputSchema, array_diff(self::properties($tool), self::properties($reference->tool))];
        }
        foreach (array_diff_key($advertised, $tools) as $name => $tool) { // get_more_tools
            $replaced[$name] = new ToolReference($tool, $handler);
            $schemas[] = [$tool->inputSchema, null, []];
        }

        RegistryAccess::setTools($registry, $replaced);
        CallToolHandlerAccess::setSchemaValidator($library, new InjectedSchemaValidator(CallToolHandlerAccess::logger($library), $schemas));
        self::$tracked ??= new \WeakMap();
        self::$tracked[$registry] = $server;
    }

    /**
     * The library's `tools/call` handler, the first in the server's request handlers, and the registry.
     *
     * @return array{?CallToolHandler, mixed}
     */
    private static function find(Builder|Server $server): array
    {
        foreach ($server instanceof Server ? ServerAccess::requestHandlers(ServerAccess::protocol($server)) : BuilderAccess::requestHandlers($server) ?? [] as $handler) {
            if ($handler instanceof CallToolHandler) {
                return [$handler, $server instanceof Server ? CallToolHandlerAccess::registry($handler) : BuilderAccess::registry($server)];
            }
        }

        return [null, null];
    }

    /**
     * The names of the tool's parameters, read from its schema as the wire carries it: mcp/sdk's types require
     * `properties`, but a registered schema can leave it out.
     *
     * @return list<array-key>
     */
    private static function properties(Tool $tool): array
    {
        $schema = JsonTree::of($tool->inputSchema);
        $properties = $schema instanceof \stdClass ? ($schema->properties ?? null) : null;

        return $properties instanceof \stdClass ? array_keys(get_object_vars($properties)) : [];
    }

    /** The tool that AgentCat advertised, read as Tool::fromArray() reads a JSON tool (empty sub-schemas stay `{}`). */
    private static function tool(mixed $tree): Tool
    {
        $data = json_decode(json_encode($tree, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \UnexpectedValueException('AgentCat advertised a tool that is not a JSON object.');
        }
        /** @phpstan-var ToolData $data Tool::fromArray() checks it */
        return Tool::fromArray($data);
    }
}
