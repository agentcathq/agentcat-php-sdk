<?php

declare(strict_types=1);

namespace AgentCat\Tests\Golden;

use AgentCat\Internal\Id\SessionId;
use AgentCat\Tests\Support\Golden;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('ac-04-6')]
#[Group('ac-03-22')]
final class DeriveVectorsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function vectors(): iterable
    {
        $rows = Golden::json('handles/derive-vectors.json');
        self::assertIsArray($rows);
        foreach ($rows as $index => $row) {
            self::assertInstanceOf(\stdClass::class, $row);
            self::assertIsString($row->id);
            self::assertTrue($row->projectId === null || is_string($row->projectId));
            self::assertIsString($row->sessionId);
            yield 'json #' . $index => [$row->id, $row->projectId, $row->sessionId];
        }

        yield 'customer-correlation-7' => ['customer-correlation-7', 'proj_test', 'ses_2cVoolHakk4pa3LZQSgvJRkTwcK'];
        yield 'cafe with an emoji' => ['café ☕', 'proj_x', 'ses_2bVQK3nSTtccWONoNR4Z7z2HC1v'];
    }

    #[DataProvider('vectors')]
    public function testDeriveMatchesTypeScript(string $id, ?string $projectId, string $expected): void
    {
        $this->assertSame($expected, SessionId::derive($id, $projectId));
    }
}
