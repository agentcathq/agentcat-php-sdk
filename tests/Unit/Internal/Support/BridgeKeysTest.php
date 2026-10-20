<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Support;

use AgentCat\Internal\Support\BridgeKeys;
use PHPUnit\Framework\TestCase;

/** M2: a framework bridge's own keys of its `agentcat` configuration, with their types. */
final class BridgeKeysTest extends TestCase
{
    /** Laravel's `exclude` default. */
    private const array DEFAULT = ['Laravel\Boost\Mcp\Boost'];

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $lines;

    #[\Override]
    protected function setUp(): void
    {
        $this->lines = new \ArrayObject();
    }

    public function testAStringKeyIsAStringOrNull(): void
    {
        $keys = $this->keys(['project_id' => 'proj_x', 'log_channel' => null, 'diagnostics_token' => 7]);

        $this->assertSame('proj_x', $keys->string('project_id'));
        $this->assertNull($keys->string('log_channel'));
        $this->assertNull($keys->string('diagnostics_endpoint'));
        $this->assertNull($keys->string('diagnostics_token'));
        $this->assertSame(['WARN: AgentCat option "agentcat.diagnostics_token" must be string or null, got int; the default is used'], $this->lines->getArrayCopy());
    }

    public function testAListIsTheDefaultWhenUnsetOrNull(): void
    {
        $this->assertSame(self::DEFAULT, $this->keys([])->strings('exclude', self::DEFAULT));
        $this->assertSame(self::DEFAULT, $this->keys(['exclude' => null])->strings('exclude', self::DEFAULT));
        $this->assertSame([], $this->keys(['exclude' => []])->strings('exclude', self::DEFAULT));
        $this->assertSame([], $this->lines->getArrayCopy());
    }

    public function testAStringIsAListOfOne(): void
    {
        $this->assertSame(['App\Mcp\Internal'], $this->keys(['exclude' => 'App\Mcp\Internal'])->strings('exclude', self::DEFAULT));
        $this->assertSame([], $this->lines->getArrayCopy());
    }

    public function testAnEmptyStringNamesNoServerSoItIsTheDefaultWithOneLine(): void
    {
        $this->assertSame(self::DEFAULT, $this->keys(['exclude' => ''])->strings('exclude', self::DEFAULT));
        $this->assertSame(['WARN: AgentCat option "agentcat.exclude" must be array or non-empty string, got empty string; the default is used'], $this->lines->getArrayCopy());
    }

    public function testAListOfAnotherTypeIsTheDefaultWithOneLine(): void
    {
        $this->assertSame(self::DEFAULT, $this->keys(['exclude' => 1.5])->strings('exclude', self::DEFAULT));
        $this->assertSame(['App\A', 'App\B'], $this->keys(['servers' => true])->strings('servers', ['App\A', 'App\B']));
        $this->assertSame([
            'WARN: AgentCat option "agentcat.exclude" must be array or string, got float; the default is used',
            'WARN: AgentCat option "agentcat.servers" must be array or string, got bool; the default is used',
        ], $this->lines->getArrayCopy());
    }

    public function testAListEntryOfAnotherTypeIsIgnoredWithOneLineAndANullOneSilently(): void
    {
        $this->assertSame(['App\A', 'App\B'], $this->keys(['exclude' => ['App\A', null, ['App\C'], 'x' => 'App\B']])->strings('exclude', self::DEFAULT));
        $this->assertSame(['WARN: AgentCat option "agentcat.exclude.2" must be string, got array; the entry is ignored'], $this->lines->getArrayCopy());
    }

    public function testProjectsAreAMapOfStringsOrNulls(): void
    {
        $this->assertSame([], $this->keys([])->projects());
        $this->assertSame([], $this->keys(['projects' => null])->projects());
        $this->assertSame(['App\A' => 'proj_a', 'App\B' => null, 'App\D' => 'proj_d'], $this->keys(['projects' => ['App\A' => 'proj_a', 'App\B' => null, 'App\C' => 5, 'App\D' => 'proj_d']])->projects());
        $this->assertSame(['WARN: AgentCat option "agentcat.projects.App\C" must be string or null, got int; the entry is ignored'], $this->lines->getArrayCopy());
    }

    public function testProjectsOfAnotherTypeAreNoneWithOneLine(): void
    {
        $this->assertSame([], $this->keys(['projects' => 'proj_x'])->projects());
        $this->assertSame(['WARN: AgentCat option "agentcat.projects" must be array or null, got string; the default is used'], $this->lines->getArrayCopy());
    }

    /** @param array<array-key, mixed> $config */
    private function keys(array $config): BridgeKeys
    {
        $lines = $this->lines;

        return new BridgeKeys($config, static function (string $line) use ($lines): void {
            $lines->append($line);
        });
    }
}
