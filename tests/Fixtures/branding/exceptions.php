<?php

declare(strict_types=1);

// The sentences of the TypeScript README's shared sections that the PHP README replaces (D65): each section's prose
// is the TypeScript prose with exactly these replacements. A sentence is replaced only where it is false for PHP or
// names the TypeScript package, its syntax or its repository. `php` null removes the sentence. ReadmeTest reads the
// sections as Branding::sections() does: one line per paragraph, list item or HTML line.

$typescriptCats = 'https://github.com/agentcathq/agentcat-typescript-sdk/tree/main/docs/cats';

return [
    'header' => [
        [
            'typescript' => '<a href="https://badge.fury.io/js/agentcat"><img src="https://badge.fury.io/js/agentcat.svg" alt="npm version"></a>',
            'php' => '<a href="https://packagist.org/packages/agentcat/sdk"><img src="https://img.shields.io/packagist/v/agentcat/sdk.svg" alt="Packagist version"></a>',
            'reason' => 'The PHP package is agentcat/sdk on Packagist, not agentcat on npm.',
        ],
        [
            'typescript' => '<a href="https://www.npmjs.com/package/agentcat"><img src="https://img.shields.io/npm/dm/agentcat.svg" alt="npm downloads"></a>',
            'php' => '<a href="https://packagist.org/packages/agentcat/sdk"><img src="https://img.shields.io/packagist/dm/agentcat/sdk.svg" alt="Packagist downloads"></a>',
            'reason' => 'The PHP package is agentcat/sdk on Packagist, not agentcat on npm.',
        ],
        [
            'typescript' => '<a href="https://www.typescriptlang.org/"><img src="https://img.shields.io/badge/%3C%2F%3E-TypeScript-%230074c1.svg" alt="TypeScript"></a>',
            'php' => '<a href="https://www.php.net/"><img src="https://img.shields.io/packagist/dependency-v/agentcat/sdk/php.svg" alt="PHP"></a>',
            'reason' => 'The SDK is written in PHP; the badge shows the PHP versions that the package requires.',
        ],
        [
            'typescript' => '<a href="https://github.com/agentcathq/agentcat-typescript-sdk/issues"><img src="https://img.shields.io/github/issues/agentcathq/agentcat-typescript-sdk.svg" alt="GitHub issues"></a>',
            'php' => '<a href="https://github.com/agentcathq/agentcat-php-sdk/issues"><img src="https://img.shields.io/github/issues/agentcathq/agentcat-php-sdk.svg" alt="GitHub issues"></a>',
            'reason' => 'The issues of the PHP SDK are in its own repository.',
        ],
        [
            'typescript' => '<a href="https://github.com/agentcathq/agentcat-typescript-sdk/actions"><img src="https://github.com/agentcathq/agentcat-typescript-sdk/workflows/CI/badge.svg" alt="CI"></a>',
            'php' => '<a href="https://github.com/agentcathq/agentcat-php-sdk/actions/workflows/ci.yml"><img src="https://github.com/agentcathq/agentcat-php-sdk/actions/workflows/ci.yml/badge.svg" alt="CI"></a>',
            'reason' => 'The badge shows the CI workflow of this repository.',
        ],
    ],
    'note' => [
        [
            'typescript' => 'AgentCat v2 introduces compatibility with the [MCP Protocol "Stateless" 2026-07-28 Update](https://blog.modelcontextprotocol.io/posts/2026-07-28/) and the coinciding [MCP TypeScript SDK v2](https://github.com/modelcontextprotocol/typescript-sdk/releases#release-@modelcontextprotocol/server@2.0.0) release that puts it into effect.',
            'php' => 'AgentCat for PHP is compatible with the [MCP Protocol "Stateless" 2026-07-28 Update](https://blog.modelcontextprotocol.io/posts/2026-07-28/) as the PHP libraries put it into effect: [`mcp/sdk`](https://github.com/modelcontextprotocol/php-sdk) over HTTP since 0.8 and over stdio since 0.9.0, and [`laravel/mcp`](https://github.com/laravel/mcp) 1.x.',
            'reason' => 'PHP servers get the 2026-07-28 update from mcp/sdk and laravel/mcp, not from the MCP TypeScript SDK v2; there is no earlier PHP release to call v1.',
        ],
    ],
    'How it works' => [
        [
            'typescript' => 'Values are validated: anything AgentCat did not issue is rejected rather than adopted, and the agent is told to re-send the ID it was given.',
            'php' => 'Values are validated: anything that is not a well-formed AgentCat session ID is rejected rather than adopted, and the agent is told to re-send the ID it was given.',
            'reason' => 'AgentCat checks the shape of a session ID, not where it came from: a well-formed ID that another server issued is adopted.',
        ],
        [
            'typescript' => 'For tools that declare an output schema, issued IDs are also mirrored into `structuredContent` (as `_mcp_instructions`), so clients that only read structured results still receive them.',
            'php' => 'For tools that declare an output schema, issued IDs are also mirrored into `structuredContent` (as `mcp_session`), so clients that only read structured results still receive them.',
            'reason' => 'The mirror is the `mcp_session` key; `_mcp_instructions` is the name it had before TypeScript SDK 2.1.0.',
        ],
    ],
    'Identifying users' => [
        [
            'typescript' => '`identify` (like every AgentCat hook) runs concurrently with your tool handler and never adds latency to tool calls: a slow lookup — or a hook that fails outright — costs analytics data for that event, never your tool\'s response time.',
            'php' => '`identify` (like every AgentCat hook) runs after the client has your tool\'s answer, so it never adds latency to that tool call: a slow lookup — or a hook that fails outright — costs analytics data for that event, never that call\'s response time.',
            'reason' => 'PHP runs every hook after the response (D04), not concurrently with the tool handler.',
        ],
        [
            'typescript' => 'The callback receives the raw MCP `request` and the request context the SDK hands to handlers.',
            'php' => 'The callback receives a `ToolCall`: the tool name, the raw arguments and `_meta`, the HTTP headers, the client\'s name and version, and the request and context objects of your MCP library.',
            'reason' => 'A PHP hook receives one ToolCall snapshot (D04, D35), not the request and the context.',
        ],
        [
            'typescript' => 'On MCP TypeScript SDK v2 that context is the SDK\'s `ServerContext` — most identity signals live on its `http` property:',
            'php' => 'Most identity signals live in its `headers`, with lower-case names (empty on stdio):',
            'reason' => 'ServerContext belongs to the MCP TypeScript SDK; in PHP the HTTP headers are a ToolCall field.',
        ],
        [
            'typescript' => 'For legacy MCP TypeScript SDK v1 servers, the structure looks like:',
            'php' => 'In Laravel, the `identify` key of `config/agentcat.php` names an invokable class that receives the same `ToolCall`; in Symfony, the `identify` key of the `agentcat` configuration names an invokable service.',
            'reason' => 'PHP has no legacy SDK form; the Laravel and Symfony bridges take each hook from their configuration (D52, D55).',
        ],
    ],
    'Redacting sensitive data' => [
        [
            'typescript' => 'It may be sync or async, and can be combined with `redactSensitiveInformation`.',
            'php' => 'It can be combined with `redactSensitiveInformation`.',
            'reason' => 'A PHP hook is a plain closure: PHP has no async hooks.',
        ],
    ],
    'Internal diagnostics' => [
        [
            'typescript' => 'To help us catch and fix broken installs, the SDK sends AgentCat a small, anonymized signal when setup or runtime errors occur — never your tool calls, your responses, or anything about your users.',
            'php' => 'To help us catch and fix broken installs, the SDK sends AgentCat every line it logs, with its time and level. The lines can contain session IDs, actor IDs, tool names, error messages and the answers of exporters that failed.',
            'reason' => 'Diagnostics carry every log line of the SDK (D07), which can name sessions, actors and tools, so the TypeScript sentence is false for PHP; the section states it plainly, with no special notice (D33).',
        ],
        [
            'typescript' => 'Records carry only operational metadata, such as your project ID (or an anonymous install ID when none is set).',
            'php' => 'Records also carry operational metadata, such as your project ID (or, when none is set, an install ID that hashes the host name and the install path), the OS, and the versions of PHP, the SDK and `mcp/sdk`.',
            'reason' => 'The log lines are more than operational metadata (D07), and the PHP install ID is a hash of the host name and the install path (D48).',
        ],
        [
            'typescript' => 'Your local `~/agentcat.log` is unchanged.',
            'php' => 'Your local log is unchanged: `~/agentcat.log` (or the PSR-3 logger of your `mcp/sdk` Builder) in plain PHP, `storage/logs/agentcat.log` in Laravel and `%kernel.logs_dir%/agentcat.log` in Symfony.',
            'reason' => 'Plain PHP logs to the Builder\'s PSR-3 logger when it has one (D30), and the Laravel and Symfony bridges write their own files (D60, D61).',
        ],
        [
            'typescript' => '- `track(server, projectId, { disableDiagnostics: true })`, or',
            'php' => '- `AgentCat::track($builder, $projectId, new Options(disableDiagnostics: true))`, or',
            'reason' => 'The PHP call syntax.',
        ],
        [
            'typescript' => '- the `DISABLE_DIAGNOSTICS` environment variable.',
            'php' => '- the `AGENTCAT_DISABLE_DIAGNOSTICS` (or `DISABLE_DIAGNOSTICS`) environment variable.',
            'reason' => 'PHP reads AGENTCAT_DISABLE_DIAGNOSTICS, and DISABLE_DIAGNOSTICS only as a fallback that turns diagnostics off (D50).',
        ],
    ],
    'Community Cats 🐱' => [
        [
            'typescript' => 'Add your cat to our community by submitting a PR with your cat\'s photo in the `docs/cats/` directory.',
            'php' => 'Add your cat to our community by submitting a PR with your cat\'s photo in the `docs/cats/` directory of the [TypeScript SDK](' . $typescriptCats . '), whose cats this README shows too.',
            'reason' => 'The cats here are compared byte for byte with the TypeScript repository\'s (D65), so a new cat goes there first.',
        ],
        [
            'typescript' => '_Want to add your cat? Create a PR adding your cat\'s photo to_ `docs/cats/` _and update this section!_',
            'php' => '_Want to add your cat? Create a PR adding your cat\'s photo to_ [`docs/cats/` of the TypeScript SDK](' . $typescriptCats . ') _and update its README!_',
            'reason' => 'The cats here are compared byte for byte with the TypeScript repository\'s (D65), so a new cat goes there first.',
        ],
    ],
];
