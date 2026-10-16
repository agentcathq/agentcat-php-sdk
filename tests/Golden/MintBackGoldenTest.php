<?php

declare(strict_types=1);

namespace AgentCat\Tests\Golden;

use AgentCat\Internal\Handles\HandleResolution;
use AgentCat\Internal\Handles\MintBack;
use AgentCat\Internal\Handles\SessionIdSource;
use AgentCat\Tests\Support\Golden;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class MintBackGoldenTest extends TestCase
{
    #[Group('ac-04-35')]
    public function testIssuedBlockMatchesTheGoldenString(): void
    {
        $id = 'ses_2cOHEO0LYGADMzRvWTXXVbbgxgm';
        $strings = Golden::json('handles/strings.json');
        $this->assertInstanceOf(\stdClass::class, $strings);
        $expected = $strings->{'MINTED_BLOCK_FOR_' . $id};

        $text = MintBack::text(new HandleResolution($id, SessionIdSource::Minted));

        $this->assertSame($expected, $text);
        $this->assertSame(207, strlen((string) $text));
        $this->assertSame('cc03c3cbe579dd55b6a52639f038d68e092f298cec638b0a30ce20de4fbbb489', hash('sha256', (string) $text));
    }

    #[Group('ac-04-37')]
    public function testUnrecognizedBlockMatchesTheGoldenString(): void
    {
        $strings = Golden::json('handles/strings.json');
        $this->assertInstanceOf(\stdClass::class, $strings);

        $text = MintBack::text(new HandleResolution('', SessionIdSource::Invalid));

        $this->assertSame($strings->UNRECOGNIZED_BLOCK, $text);
        $this->assertSame(236, strlen((string) $text));
        $this->assertSame('79b9409dd611943177d8fdc861398ba789b9c45d361e0dc2bbbca3c593867954', hash('sha256', (string) $text));
        $this->assertStringNotContainsString('ses_', (string) $text);
    }
}
