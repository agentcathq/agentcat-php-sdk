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
            ->excluding(Selector::inNamespace('AgentCat\McpSdk'), Selector::inNamespace('AgentCat\Tests'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('Mcp'))
            ->because('mcp/sdk is optional: only AgentCat\McpSdk may reference it (01 AC-90)');
    }
}
