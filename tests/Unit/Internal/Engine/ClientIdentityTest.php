<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Engine;

use AgentCat\Internal\Engine\ClientIdentity;
use Mcp\Schema\Implementation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('ac-02-44')]
#[Group('ac-01-81')]
final class ClientIdentityTest extends TestCase
{
    public function testFirstCandidateWins(): void
    {
        $this->assertSame(['env', '1'], ClientIdentity::pick(['name' => 'env', 'version' => '1'], ['name' => 'meta']));
    }

    public function testEachFieldIsNarrowedOnItsOwn(): void
    {
        $this->assertSame(['n', null], ClientIdentity::pick(['name' => 'n', 'version' => 2]));
        $this->assertSame([null, 'v'], ClientIdentity::pick(['name' => 2, 'version' => 'v']));
    }

    public function testACandidateWithoutAStringFieldIsSkipped(): void
    {
        $this->assertSame(['next', null], ClientIdentity::pick(['name' => 5, 'version' => 6], ['name' => 'next']));
    }

    public function testObjectsAreReadLikeArrays(): void
    {
        $plain = new \stdClass();
        $plain->name = 'std';
        $plain->version = '2';

        $this->assertSame(['impl', '9.9'], ClientIdentity::pick(new Implementation('impl', '9.9')));
        $this->assertSame(['std', '2'], ClientIdentity::pick($plain));
    }

    public function testOtherValuesAreSkipped(): void
    {
        $this->assertSame([null, null], ClientIdentity::pick(null, 'name', static fn(): int => 1, 7));
        $this->assertSame([null, null], ClientIdentity::pick());
        $this->assertSame(['x', null], ClientIdentity::pick(null, 'name', static fn(): int => 1, ['name' => 'x']));
    }
}
