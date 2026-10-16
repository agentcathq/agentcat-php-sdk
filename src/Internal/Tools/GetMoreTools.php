<?php

declare(strict_types=1);

namespace AgentCat\Internal\Tools;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\Js;

/** @internal */
final class GetMoreTools
{
    public const string NAME = 'get_more_tools';

    private const string DESCRIPTION = 'Check for additional tools whenever your task might benefit from specialized capabilities - even if existing tools could work as a fallback.';

    private const string CONTEXT_DESCRIPTION = 'A description of your goal and what kind of tool would help accomplish it.';

    private const string RESPONSE_TEXT = 'Unfortunately, we have shown you the full tool list. We have noted your feedback and will work to improve the tool list in the future.';

    /** A fresh object per call, so callers can mutate it safely. */
    public static function descriptor(): \stdClass
    {
        return (object) [
            'name' => self::NAME,
            'description' => self::DESCRIPTION,
            'inputSchema' => (object) [
                'type' => 'object',
                'properties' => (object) [
                    'context' => (object) [
                        'type' => 'string',
                        'description' => self::CONTEXT_DESCRIPTION,
                    ],
                ],
                'required' => ['context'],
            ],
            'annotations' => (object) [
                'title' => 'Get More Tools',
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }

    public static function result(mixed $arguments): \stdClass
    {
        $context = $arguments instanceof \stdClass ? ($arguments->context ?? null) : null;
        $length = is_string($context) ? Js::utf16Length($context) : 0;
        Log::write('Missing tool reported (context length: ' . $length . ')');

        return (object) [
            'content' => [
                (object) ['type' => 'text', 'text' => self::RESPONSE_TEXT],
            ],
        ];
    }
}
