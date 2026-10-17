<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Server\Builder;

/**
 * The tools of the adapter tests. Each method adds one tool to a Builder and returns its recorder, so a test reads
 * the arguments the tool received. The schemas are literal arrays: the `Tool` schema shapes differ between the two
 * mcp/sdk revisions PHPStan checks.
 */
final class Tools
{
    private const array NO_ARGUMENTS = ['type' => 'object', 'properties' => [], 'required' => []];

    /**
     * `echo`: `{msg: string}`, required; answers `echo: <msg>`.
     *
     * @param array<array-key, mixed> $properties more input properties, after `msg`
     */
    public static function echo(Builder $builder, array $properties = []): RecordingTool
    {
        $tool = new RecordingTool(static fn(array $arguments): string => 'echo: ' . (is_string($arguments['msg'] ?? null) ? $arguments['msg'] : ''));
        $schema = ['type' => 'object', 'properties' => (object) (['msg' => ['type' => 'string']] + $properties), 'required' => ['msg']];
        $builder->add(new Tool(name: 'echo', title: null, inputSchema: $schema, description: 'Echo', annotations: null), $tool);

        return $tool;
    }

    /** `strict`: `{msg: string}`, required, with `additionalProperties: false`. Records `msg`, answers `ok`. */
    public static function strict(Builder $builder): RecordingTool
    {
        $tool = new RecordingTool();
        $builder->addTool(
            static function (string $msg) use ($tool): string {
                $tool->calls[] = ['msg' => $msg];

                return 'ok';
            },
            name: 'strict',
            description: 'Strict',
            inputSchema: ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']], 'required' => ['msg'], 'additionalProperties' => false],
        );

        return $tool;
    }

    /**
     * `structured`: declares an object `outputSchema` and answers this `structuredContent` with one text block `x`.
     *
     * @param array<array-key, mixed> $structuredContent
     */
    public static function structured(Builder $builder, array $structuredContent = ['echoed' => 'x']): RecordingTool
    {
        $tool = new RecordingTool(static fn(): CallToolResult => new CallToolResult([new TextContent('x')], structuredContent: $structuredContent));
        $builder->add(new Tool(
            name: 'structured',
            title: null,
            inputSchema: self::NO_ARGUMENTS,
            description: 'Structured',
            annotations: null,
            outputSchema: ['type' => 'object', 'properties' => ['echoed' => ['type' => 'string']]],
        ), $tool);

        return $tool;
    }

    /** `fails`: throws `ToolCallException('no luck')`, which mcp/sdk turns into an `isError` result. */
    public static function fails(Builder $builder): RecordingTool
    {
        $tool = new RecordingTool(static function (): never {
            throw new ToolCallException('no luck');
        });
        $builder->add(new Tool(name: 'fails', title: null, inputSchema: self::NO_ARGUMENTS, description: 'Fails', annotations: null), $tool);

        return $tool;
    }

    /** `boom`: throws a `RuntimeException` caused by a `LogicException`, which mcp/sdk answers with -32603. */
    public static function boom(Builder $builder): RecordingTool
    {
        $tool = new RecordingTool(static function (): never {
            throw new \RuntimeException('tool boom', 0, new \LogicException('cause'));
        });
        $builder->add(new Tool(name: 'boom', title: null, inputSchema: self::NO_ARGUMENTS, description: 'Boom', annotations: null), $tool);

        return $tool;
    }

    /** `own`: declares its own required `session_id`. Answers `ok`. */
    public static function own(Builder $builder): RecordingTool
    {
        $tool = new RecordingTool();
        $schema = ['type' => 'object', 'properties' => ['session_id' => ['type' => 'string']], 'required' => ['session_id']];
        $builder->add(new Tool(name: 'own', title: null, inputSchema: $schema, description: 'Own', annotations: null), $tool);

        return $tool;
    }
}
