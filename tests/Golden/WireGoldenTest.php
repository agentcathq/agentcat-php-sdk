<?php

declare(strict_types=1);

namespace AgentCat\Tests\Golden;

use AgentCat\Event;
use AgentCat\Internal\Wire\WireEncoder;
use AgentCat\Tests\Support\Golden;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\WireEvents;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class WireGoldenTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Event}>
     */
    public static function goldens(): iterable
    {
        yield 'g1' => ['wire/g1-tool-call-anonymous.json', WireEvents::g1()];
        yield 'g2' => ['wire/g2-identified-no-arguments.json', WireEvents::g2()];
        yield 'g3' => ['wire/g3-errored-tool-call.json', WireEvents::g3()];
        yield 'g4' => ['wire/g4-custom-no-session.json', WireEvents::g4()];
    }

    #[Group('ac-07-g1')]
    #[Group('ac-07-g2')]
    #[Group('ac-07-g3')]
    #[Group('ac-07-g4')]
    #[DataProvider('goldens')]
    public function testEncodedBodyMatchesGolden(string $file, Event $event): void
    {
        JsonAssert::assertSameJson(Golden::text($file), WireEncoder::encode($event));
    }
}
