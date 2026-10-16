<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit;

use AgentCat\AgentCatIDPrefixes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class AgentCatIDPrefixesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, AgentCatIDPrefixes}>
     */
    public static function prefixes(): iterable
    {
        yield 'session' => ['ses', AgentCatIDPrefixes::Session];
        yield 'event' => ['evt', AgentCatIDPrefixes::Event];
        yield 'agent' => ['agt', AgentCatIDPrefixes::Agent];
    }

    #[Group('ac-01-89')]
    #[DataProvider('prefixes')]
    public function testCaseMatchesTypeScript(string $value, AgentCatIDPrefixes $case): void
    {
        $this->assertSame($case, AgentCatIDPrefixes::from($value));
        $this->assertSame($value, $case->value);
    }

    #[Group('ac-01-89')]
    public function testOnlyThreeCasesExist(): void
    {
        $this->assertCount(\count(iterator_to_array(self::prefixes())), AgentCatIDPrefixes::cases());
    }
}
