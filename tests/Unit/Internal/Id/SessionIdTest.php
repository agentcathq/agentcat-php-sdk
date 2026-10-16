<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Id;

use AgentCat\Internal\Handles\SessionIdSource;
use AgentCat\Internal\Id\Ksuid;
use AgentCat\Internal\Id\SessionId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('ac-04-5')]
#[Group('ac-04-7')]
#[Group('ac-04-8')]
final class SessionIdTest extends TestCase
{
    public function testMintMatchesPatternAndIsRecent(): void
    {
        $id = SessionId::mint();
        $this->assertMatchesRegularExpression(SessionId::PATTERN, $id);
        $this->assertNotSame($id, SessionId::mint());
        $decoded = Ksuid::decode(substr($id, 4));
        $this->assertNotNull($decoded);
        $this->assertEqualsWithDelta(intdiv(time() * 1000 - 1_400_000_000_000, 1000), $decoded['timestamp'], 2);
    }

    public function testIsValidAcceptsGeneratedIds(): void
    {
        $this->assertTrue(SessionId::isValid(SessionId::mint()));
        $this->assertTrue(SessionId::isValid(SessionId::derive('anything', 'proj_1')));
        $this->assertTrue(SessionId::isValid('ses_parent' . str_repeat('0', 21)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'task id' => ['task_2xF9kQm3rTvB8nLpYw7ZcHd4Ke'];
        yield 'no prefix' => ['2xF9kQm3rTvB8nLpYw7ZcHd4Ke1'];
        yield 'short' => ['ses_abc'];
        yield 'too long' => ['ses_' . str_repeat('a', 28)];
        yield 'empty' => [''];
        yield 'prefix only' => ['ses_'];
        yield 'foreign' => ['my-app-session-42'];
        yield 'dashes' => ['ses_' . str_repeat('-', 27)];
        yield 'space' => ['ses_ ' . str_repeat('a', 26)];
        yield 'trailing newline' => ['ses_' . str_repeat('a', 27) . "\n"];
    }

    #[DataProvider('invalidIds')]
    public function testIsValidRejects(string $value): void
    {
        $this->assertFalse(SessionId::isValid($value));
    }

    public function testFromHookValueDerivesFromTrimmedString(): void
    {
        $this->assertSame(
            ['ses_2afjLT0irA6g3tOA5CklOPgcSqQ', SessionIdSource::Hook],
            SessionId::fromHookValue('  corr-1  ', 'proj_1'),
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableHookValues(): iterable
    {
        yield 'null' => [null];
        yield 'blank' => ['   '];
        yield 'int' => [42];
        yield 'array' => [['x']];
    }

    #[DataProvider('unusableHookValues')]
    public function testFromHookValueMintsForUnusableValues(mixed $value): void
    {
        [$id, $source] = SessionId::fromHookValue($value, 'proj_1');
        $this->assertTrue(SessionId::isValid($id));
        $this->assertSame(SessionIdSource::Minted, $source);
    }

    public function testDeriveTreatsEmptyProjectLikeNull(): void
    {
        $this->assertSame(SessionId::derive('x', null), SessionId::derive('x', ''));
        $this->assertNotSame(SessionId::derive('x', null), SessionId::derive('x', 'p'));
    }
}
