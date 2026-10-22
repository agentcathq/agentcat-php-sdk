<?php

declare(strict_types=1);

namespace AgentCat\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class LayeringTest
{
    #[TestRule]
    public function internalDoesNotDependOnFrameworks(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('AgentCat\Internal'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace('Mcp'),
                Selector::inNamespace('Laravel'),
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Symfony'),
            )
            ->because('the SDK core must stay framework independent');
    }

    #[TestRule]
    public function onlyTheAdapterDependsOnMcpSdk(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('AgentCat'))
            ->excluding(Selector::inNamespace('AgentCat\McpSdk'), Selector::inNamespace('AgentCat\Symfony'), Selector::inNamespace('AgentCat\Tests'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('Mcp'))
            ->because('mcp/sdk is optional: only AgentCat\McpSdk and the Symfony bundle, which configures Mcp\Server\Builder services, may reference it (ac-01-90)');
    }

    #[TestRule]
    public function onlyTheLaravelPartsDependOnLaravel(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('AgentCat'))
            ->excluding(Selector::inNamespace('AgentCat\LaravelMcp'), Selector::inNamespace('AgentCat\Laravel'), Selector::inNamespace('AgentCat\Tests'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('Laravel'), Selector::inNamespace('Illuminate'))
            ->because('laravel/mcp and Laravel are optional (ac-01-90)');
    }

    #[TestRule]
    public function theLaravelAdapterNeedsNoMcpSdk(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('AgentCat\LaravelMcp'), Selector::inNamespace('AgentCat\Laravel'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('Mcp'), Selector::inNamespace('AgentCat\McpSdk'))
            ->because('a Laravel application without mcp/sdk must load every Laravel class');
    }

    #[TestRule]
    public function onlyTheSymfonyBundleDependsOnSymfonyFrameworkParts(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('AgentCat'))
            ->excluding(Selector::inNamespace('AgentCat\Symfony'), Selector::inNamespace('AgentCat\Laravel'), Selector::inNamespace('AgentCat\LaravelMcp'), Selector::inNamespace('AgentCat\Tests'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace('Symfony\Component\HttpKernel'),
                Selector::inNamespace('Symfony\Component\DependencyInjection'),
                Selector::inNamespace('Symfony\Component\Config'),
                Selector::inNamespace('Symfony\Component\HttpFoundation'),
                Selector::inNamespace('Symfony\Component\EventDispatcher'),
                Selector::inNamespace('Symfony\Bundle'),
            )
            ->because('Symfony is optional (ac-01-90)');
    }

    #[TestRule]
    public function theSymfonyBundleNamesNoBundleClass(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('AgentCat\Symfony'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('Symfony\AI'), Selector::inNamespace('AgentCat\Laravel'), Selector::inNamespace('AgentCat\LaravelMcp'), Selector::inNamespace('AgentCat\McpSdk'))
            ->because('the bundle reads symfony/mcp-bundle service IDs and tags only, so it loads without the bundle, and reaches the mcp/sdk adapter only through Internal\Engine\Libraries');
    }

    #[TestRule]
    public function theMcpSdkAdapterNeedsNoLaravel(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('AgentCat\McpSdk'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('AgentCat\LaravelMcp'), Selector::inNamespace('AgentCat\Laravel'))
            ->because('an mcp/sdk application without Laravel must load every mcp/sdk class');
    }
}
