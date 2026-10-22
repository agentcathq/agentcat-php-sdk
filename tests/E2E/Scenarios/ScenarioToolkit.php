<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E\Scenarios;

use AgentCat\Tests\Support\McpSdk\RecordingTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\Builder;

/**
 * The five tools of the TypeScript SDK's end-to-end toolkit, on an mcp/sdk Builder, in its order:
 *
 * - `echo` `{msg}`: answers `msg`;
 * - `loose` (no `additionalProperties`): unknown keys reach the handler; answers its arguments as JSON;
 * - `strict` (`additionalProperties: false`): answers `msg`;
 * - `structured` `{msg}` with the output schema `{echoed}`: answers `msg` and `structuredContent` `{echoed: msg}`;
 * - `boom`: throws `ToolCallException('boom')`, which mcp/sdk answers with an `isError` result.
 *
 * Each handler records in the sink the arguments that reach it after AgentCat's strip: the raw argument bag of an
 * explicit handler, or, for `strict`, its one parameter. The schemas are literal arrays: the `Tool` schema shapes
 * differ between the two mcp/sdk revisions PHPStan checks.
 */
final class ScenarioToolkit
{
    public const string PROJECT_ID = 'proj_ttttttttttttttttttttttttttt';

    public const string SERVER_NAME = 'e2e-scenarios';

    public const string SERVER_VERSION = '1.0.0';

    private const array MSG = ['type' => 'string', 'description' => 'Message to echo'];

    public static function builder(Sink $sink): Builder
    {
        return Server::builder()
            ->setServerInfo(self::SERVER_NAME, self::SERVER_VERSION)
            ->add(
                new Tool(name: 'echo', title: null, inputSchema: ['type' => 'object', 'properties' => ['msg' => self::MSG], 'required' => ['msg']], description: 'Echo a message back', annotations: null),
                self::handler($sink, 'echo', static fn(array $arguments): string => self::msg($arguments)),
            )
            ->add(
                new Tool(name: 'loose', title: null, inputSchema: ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']], 'required' => []], description: 'Loose schema: unknown keys pass through to the handler', annotations: null),
                self::handler($sink, 'loose', static fn(array $arguments): string => json_encode((object) $arguments, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            )
            // A Tool definition cannot carry additionalProperties: a reflected handler gets the one parameter it
            // declares. mcp/sdk validates the call against this schema, so a handle that reached it would fail the call.
            ->addTool(
                static function (string $msg) use ($sink): string {
                    $sink->record('strict', ['msg' => $msg]);

                    return $msg;
                },
                name: 'strict',
                description: 'Strict schema: additionalProperties are rejected',
                inputSchema: ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']], 'required' => ['msg'], 'additionalProperties' => false],
            )
            ->add(
                new Tool(
                    name: 'structured',
                    title: null,
                    inputSchema: ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']], 'required' => ['msg']],
                    description: 'Returns structuredContent per its outputSchema',
                    annotations: null,
                    outputSchema: ['type' => 'object', 'properties' => ['echoed' => ['type' => 'string', 'description' => 'The echoed message']], 'required' => ['echoed']],
                ),
                self::handler($sink, 'structured', static fn(array $arguments): CallToolResult => new CallToolResult(
                    [new TextContent(self::msg($arguments))],
                    structuredContent: ['echoed' => self::msg($arguments)],
                )),
            )
            ->add(
                new Tool(name: 'boom', title: null, inputSchema: ['type' => 'object', 'properties' => [], 'required' => []], description: 'Always throws', annotations: null),
                self::handler($sink, 'boom', static function (): never {
                    throw new ToolCallException('boom');
                }),
            );
    }

    /** @param \Closure(array<string, mixed>): mixed $respond */
    private static function handler(Sink $sink, string $tool, \Closure $respond): RecordingTool
    {
        return new RecordingTool(static function (array $arguments) use ($sink, $tool, $respond): mixed {
            $sink->record($tool, $arguments);

            return $respond($arguments);
        });
    }

    /** @param array<array-key, mixed> $arguments */
    private static function msg(array $arguments): string
    {
        return is_string($arguments['msg'] ?? null) ? $arguments['msg'] : '';
    }
}
