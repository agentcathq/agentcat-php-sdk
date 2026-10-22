<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony\FullPage;

use Mcp\Capability\Attribute\McpTool;

/**
 * `t01` to `t14`: no input; each answers its name. With `echo`, the 15 tools of `TestKernel::FULL_PAGE`, one full page.
 * Outside `Tools/`, so only a test that gives it to a server's registry exposes it.
 */
final class FullPageTools
{
    #[McpTool(name: 't01')]
    public function t01(): string
    {
        return 't01';
    }

    #[McpTool(name: 't02')]
    public function t02(): string
    {
        return 't02';
    }

    #[McpTool(name: 't03')]
    public function t03(): string
    {
        return 't03';
    }

    #[McpTool(name: 't04')]
    public function t04(): string
    {
        return 't04';
    }

    #[McpTool(name: 't05')]
    public function t05(): string
    {
        return 't05';
    }

    #[McpTool(name: 't06')]
    public function t06(): string
    {
        return 't06';
    }

    #[McpTool(name: 't07')]
    public function t07(): string
    {
        return 't07';
    }

    #[McpTool(name: 't08')]
    public function t08(): string
    {
        return 't08';
    }

    #[McpTool(name: 't09')]
    public function t09(): string
    {
        return 't09';
    }

    #[McpTool(name: 't10')]
    public function t10(): string
    {
        return 't10';
    }

    #[McpTool(name: 't11')]
    public function t11(): string
    {
        return 't11';
    }

    #[McpTool(name: 't12')]
    public function t12(): string
    {
        return 't12';
    }

    #[McpTool(name: 't13')]
    public function t13(): string
    {
        return 't13';
    }

    #[McpTool(name: 't14')]
    public function t14(): string
    {
        return 't14';
    }
}
