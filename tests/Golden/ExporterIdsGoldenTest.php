<?php

declare(strict_types=1);

namespace AgentCat\Tests\Golden;

use AgentCat\Internal\Id\UuidV7;
use AgentCat\Internal\Telemetry\TraceContext;
use AgentCat\Tests\Support\ExporterGolden;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ExporterIdsGoldenTest extends TestCase
{
    #[Group('ac-06-1')]
    #[Group('ac-06-2')]
    #[Group('ac-06-3')]
    #[Group('ac-06-7')]
    public function testTheIdsOfTheFixtureHeader(): void
    {
        $ids = ExporterGolden::ids();
        $this->assertSame($ids['traceId'], TraceContext::traceId($ids['sessionId']));
        $this->assertSame($ids['spanId'], TraceContext::spanId($ids['eventId']));
        $this->assertSame($ids['ddTrace'], TraceContext::datadogTraceId($ids['sessionId']));
        $this->assertSame($ids['ddSpan'], TraceContext::datadogSpanId($ids['eventId']));
        $this->assertSame($ids['uuid(session)'], UuidV7::fromPrefixedId($ids['sessionId']));
        $this->assertSame($ids['uuid(event)'], UuidV7::fromPrefixedId($ids['eventId']));
        $this->assertSame($ids['uuid(my-customer-task-id)'], UuidV7::fromPrefixedId('my-customer-task-id'));
        $this->assertSame($ids["uuid('')"], UuidV7::fromPrefixedId(''));
        $this->assertSame($ids['uuid(ses_session45600000000000000000)'], UuidV7::fromPrefixedId('ses_session45600000000000000000'));
        $this->assertSame($ids['uuid(abc_def_ghi)'], UuidV7::fromPrefixedId('abc_def_ghi'));
        $this->assertSame($ids['uuid(27 dashes)'], UuidV7::fromPrefixedId('x_' . str_repeat('-', 27)));
    }
}
