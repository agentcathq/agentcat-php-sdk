<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Handles;

use AgentCat\Internal\Handles\HandleResolver;
use AgentCat\Internal\Handles\SessionIdSource;
use AgentCat\Internal\Id\SessionId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class HandleResolverTest extends TestCase
{
    private const string VALID = 'ses_abcdefghijklmnopqrstuvwxyz0';

    /** @param array<string, mixed> $props */
    private function args(array $props): \stdClass
    {
        return (object) $props;
    }

    /** @return iterable<string, array{mixed}> */
    public static function omittedSessionValues(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'int' => [42];
        yield 'bool' => [true];
        yield 'object' => [new \stdClass()];
        yield 'list' => [['x']];
        yield 'null' => [null];
    }

    #[Group('ac-04-9')]
    public function testOmittedSessionIdMints(): void
    {
        $r = HandleResolver::resolve($this->args(['text' => 'x']), false, false, true);
        $this->assertSame(SessionIdSource::Minted, $r->source);
        $this->assertTrue(SessionId::isValid($r->sessionId));
        $this->assertNull($r->agentId);
        $this->assertFalse($r->hookMode);
    }

    #[Group('ac-04-9')]
    #[DataProvider('omittedSessionValues')]
    public function testNonUsableSessionIdMints(mixed $value): void
    {
        $r = HandleResolver::resolve($this->args(['session_id' => $value]), false, false, true);
        $this->assertSame(SessionIdSource::Minted, $r->source);
        $this->assertTrue(SessionId::isValid($r->sessionId));
    }

    /** @return iterable<string, array{string}> */
    public static function startValues(): iterable
    {
        foreach (['start', 'START', 'Start', 'sTaRt', '  start  ', " START\t"] as $v) {
            yield bin2hex($v) => [$v];
        }
    }

    #[Group('ac-04-10')]
    #[DataProvider('startValues')]
    public function testStartSentinelMintsFreshIds(string $value): void
    {
        $a = HandleResolver::resolve($this->args(['session_id' => $value]), false, false, true);
        $b = HandleResolver::resolve($this->args(['session_id' => $value]), false, false, true);
        $this->assertSame(SessionIdSource::Minted, $a->source);
        $this->assertTrue(SessionId::isValid($a->sessionId));
        $this->assertNotSame($a->sessionId, $b->sessionId);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        foreach (['restart', 'start now', 'starts', 'star t', 'nope', 'sk_live_secret_token', 'ses_abc'] as $v) {
            yield $v => [$v];
        }
    }

    #[Group('ac-04-11')]
    #[DataProvider('invalidValues')]
    public function testInvalidValueIsNeverStored(string $value): void
    {
        $r = HandleResolver::resolve($this->args(['session_id' => $value]), false, false, true);
        $this->assertSame(SessionIdSource::Invalid, $r->source);
        $this->assertSame('', $r->sessionId);
        $this->assertStringNotContainsString($value, serialize($r));
    }

    #[Group('ac-04-12')]
    public function testSuppliedValueIsTrimmed(): void
    {
        $r = HandleResolver::resolve($this->args(['session_id' => ' ' . self::VALID . ' ']), false, false, true);
        $this->assertSame(SessionIdSource::Supplied, $r->source);
        $this->assertSame(self::VALID, $r->sessionId);
    }

    /** @return iterable<string, array{\stdClass}> */
    public static function foreignArgs(): iterable
    {
        yield 'start' => [(object) ['session_id' => 'start']];
        yield 'valid' => [(object) ['session_id' => self::VALID]];
        yield 'none' => [new \stdClass()];
    }

    #[Group('ac-04-13')]
    #[DataProvider('foreignArgs')]
    public function testForeignWhenParamIsNotOurs(\stdClass $args): void
    {
        $r = HandleResolver::resolve($args, false, false, false);
        $this->assertSame(SessionIdSource::Foreign, $r->source);
        $this->assertSame('', $r->sessionId);
    }

    #[Group('ac-04-14')]
    public function testHookModeDoesNotReadArguments(): void
    {
        $r = HandleResolver::resolve($this->args(['session_id' => self::VALID]), true, false, true);
        $this->assertSame(SessionIdSource::Hook, $r->source);
        $this->assertSame('', $r->sessionId);
        $this->assertTrue($r->hookMode);
    }

    #[Group('ac-04-14')]
    public function testHookModeWinsOverForeign(): void
    {
        $r = HandleResolver::resolve(new \stdClass(), true, false, false);
        $this->assertSame(SessionIdSource::Hook, $r->source);
    }

    #[Group('ac-04-15')]
    public function testAgentIdIgnoredWhenTrackingOff(): void
    {
        $r = HandleResolver::resolve($this->args(['agent_id' => 'opus']), false, false, true);
        $this->assertNull($r->agentId);
    }

    #[Group('ac-04-15')]
    public function testAgentIdTrimmedWhenTrackingOn(): void
    {
        $r = HandleResolver::resolve($this->args(['agent_id' => ' opus|cc|x ']), false, true, true);
        $this->assertSame('opus|cc|x', $r->agentId);
    }

    #[Group('ac-04-15')]
    public function testAgentIdReadEvenInHookAndForeignModes(): void
    {
        $this->assertSame('a', HandleResolver::resolve($this->args(['agent_id' => 'a']), true, true, true)->agentId);
        $this->assertSame('a', HandleResolver::resolve($this->args(['agent_id' => 'a']), false, true, false)->agentId);
    }

    /** @return iterable<string, array{mixed}> */
    public static function badAgentValues(): iterable
    {
        yield 'omitted' => [new \stdClass()];
        yield 'int' => [(object) ['agent_id' => 5]];
        yield 'object' => [(object) ['agent_id' => new \stdClass()]];
        yield 'blank' => [(object) ['agent_id' => ' ']];
    }

    #[Group('ac-04-15')]
    #[DataProvider('badAgentValues')]
    public function testAgentIdNullWhenOmittedOrNotAString(mixed $args): void
    {
        $this->assertNull(HandleResolver::resolve($args, false, true, true)->agentId);
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonObjects(): iterable
    {
        yield 'null' => [null];
        yield 'list' => [['session_id' => 'x']];
        yield 'string' => ['session_id'];
    }

    #[DataProvider('nonObjects')]
    public function testNonObjectArgumentsHaveNoHandles(mixed $args): void
    {
        $r = HandleResolver::resolve($args, false, true, true);
        $this->assertSame(SessionIdSource::Minted, $r->source);
        $this->assertNull($r->agentId);
    }
}
