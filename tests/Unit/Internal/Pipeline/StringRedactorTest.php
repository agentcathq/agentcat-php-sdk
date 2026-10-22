<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Pipeline;

use AgentCat\Event;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Pipeline\StringRedactor;
use AgentCat\Tests\Support\Samples\PlainThing;
use AgentCat\Tests\Support\Samples\SerializableThing;
use AgentCat\Tests\Support\Samples\UnitStatus;
use AgentCat\Tests\Support\Samples\VisibilityThing;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class StringRedactorTest extends TestCase
{
    /** @return \Closure(string): string */
    private function mask(): \Closure
    {
        return static fn(string $text): string => '[REDACTED-' . strlen($text) . ']';
    }

    #[Group('ac-05-R1')]
    public function testRedactsUserIntentAndPassesExactText(): void
    {
        $seen = [];
        $out = StringRedactor::redact(
            new Event(userIntent: 'sensitive user intent'),
            static function (string $text) use (&$seen): string {
                $seen[] = $text;

                return '[REDACTED-' . strlen($text) . ']';
            },
        );

        $this->assertSame('[REDACTED-21]', $out->userIntent);
        $this->assertSame(['sensitive user intent'], $seen);
    }

    #[Group('ac-05-R2')]
    #[Group('ac-01-48')]
    public function testProtectedFieldsAreNeverRedacted(): void
    {
        $calls = 0;
        $event = new Event(
            id: 'evt_1',
            sessionId: 'ses_1',
            projectId: 'proj_1',
            eventType: 'mcp:tools/call',
            serverName: 'srv',
            serverVersion: '1.0',
            clientName: 'cli',
            clientVersion: '2.0',
            identifyActorGivenId: 'user-1',
            identifyActorName: 'Ann',
            resourceName: 'tool',
            tags: ['env' => 'prod'],
            properties: Json::decode('{"nested":{"deep":"x"},"list":["y"]}'),
            actorId: 'actor-1',
        );

        $out = StringRedactor::redact($event, static function (string $text) use (&$calls): string {
            ++$calls;

            return 'X';
        });

        $this->assertSame(0, $calls);
        $this->assertSame('evt_1', $out->id);
        $this->assertSame('ses_1', $out->sessionId);
        $this->assertSame('proj_1', $out->projectId);
        $this->assertSame('mcp:tools/call', $out->eventType);
        $this->assertSame('srv', $out->serverName);
        $this->assertSame('1.0', $out->serverVersion);
        $this->assertSame('cli', $out->clientName);
        $this->assertSame('2.0', $out->clientVersion);
        $this->assertSame('user-1', $out->identifyActorGivenId);
        $this->assertSame('Ann', $out->identifyActorName);
        $this->assertSame('tool', $out->resourceName);
        $this->assertSame('actor-1', $out->actorId);
        $this->assertSame(['env' => 'prod'], $out->tags);
        $this->assertEquals($event->properties, $out->properties);
    }

    #[Group('ac-05-R3')]
    public function testProtectionIsTopLevelOnly(): void
    {
        $out = StringRedactor::redact(
            new Event(parameters: Json::decode('{"nested":{"deeply":{"sessionId":"This should be redacted"}}}')),
            $this->mask(),
        );

        $this->assertEquals(Json::decode('{"nested":{"deeply":{"sessionId":"[REDACTED-23]"}}}'), $out->parameters);
    }

    #[Group('ac-05-R4')]
    public function testArraysAreRedactedElementWise(): void
    {
        $out = StringRedactor::redact(
            new Event(parameters: ['tags' => ['sensitive1', 'sensitive2', 'sensitive3'], 'numbers' => [1, 2, 3]]),
            $this->mask(),
        );

        $this->assertSame(
            ['tags' => ['[REDACTED-10]', '[REDACTED-10]', '[REDACTED-10]'], 'numbers' => [1, 2, 3]],
            $out->parameters,
        );
    }

    #[Group('ac-05-R5')]
    public function testScalarsAndKeys(): void
    {
        $out = StringRedactor::redact(
            new Event(
                response: Json::decode('{"nothing":null,"empty":"","i":3,"f":1.5,"t":true,"secret_key":"v"}'),
            ),
            $this->mask(),
        );

        $this->assertNull($out->userIntent);
        $this->assertEquals(
            Json::decode('{"nothing":null,"empty":"[REDACTED-0]","i":3,"f":1.5,"t":true,"secret_key":"[REDACTED-1]"}'),
            $out->response,
        );
    }

    #[Group('ac-05-R6')]
    public function testDatesKeepTheSameInstance(): void
    {
        $date = new \DateTimeImmutable('2020-01-01');
        $event = new Event(parameters: ['when' => $date]);

        $out = StringRedactor::redact($event, $this->mask());

        $this->assertSame($event->timestamp, $out->timestamp);
        $this->assertIsArray($out->parameters);
        $this->assertSame($date, $out->parameters['when'] ?? null);
    }

    #[Group('ac-05-R7')]
    public function testClosuresAreSkippedInObjectsAndKeptInLists(): void
    {
        $fn = static fn(): int => 1;
        $object = new \stdClass();
        $object->callback = $fn;
        $object->name = 'x';

        $out = StringRedactor::redact(
            new Event(parameters: $object, response: ['f' => $fn, 'n' => 'x'], properties: [$fn]),
            $this->mask(),
        );

        $this->assertInstanceOf(\stdClass::class, $out->parameters);
        $this->assertFalse(property_exists($out->parameters, 'callback'));
        $this->assertSame('[REDACTED-1]', $out->parameters->name);
        $this->assertSame(['n' => '[REDACTED-1]'], $out->response);
        $this->assertSame([$fn], $out->properties);
    }

    #[Group('ac-05-R8')]
    public function testErrorFieldIsRedacted(): void
    {
        $out = StringRedactor::redact(
            new Event(
                isError: true,
                error: Json::decode('{"message":"Sensitive error message","code":"ERR_001","stack":"Error: Sensitive error message\n    at functionName (file.js:10:5)"}'),
            ),
            $this->mask(),
        );

        $this->assertEquals(
            Json::decode('{"message":"[REDACTED-23]","code":"[REDACTED-7]","stack":"[REDACTED-65]"}'),
            $out->error,
        );
        $this->assertTrue($out->isError);
    }

    #[Group('ac-05-R9')]
    public function testIdentifyActorDataIsRedacted(): void
    {
        $out = StringRedactor::redact(new Event(identifyActorData: ['email' => 'a@b.c']), $this->mask());

        $this->assertSame(['email' => '[REDACTED-5]'], $out->identifyActorData);
    }

    #[Group('ac-05-R10')]
    public function testMetadataStringsAreRedacted(): void
    {
        $out = StringRedactor::redact(
            new Event(ipAddress: '1.2.3.4', sdkLanguage: 'PHP', agentcatVersion: '0.1.0', userIntent: 'why'),
            $this->mask(),
        );

        $this->assertSame('[REDACTED-7]', $out->ipAddress);
        $this->assertSame('[REDACTED-3]', $out->sdkLanguage);
        $this->assertSame('[REDACTED-5]', $out->agentcatVersion);
        $this->assertSame('[REDACTED-3]', $out->userIntent);
    }

    #[Group('ac-05-R11')]
    public function testCyclesTerminateAndSharedObjectsAreRedactedOnce(): void
    {
        $req = new \stdClass();
        $res = new \stdClass();
        $req->q = 'secret';
        $req->res = $res;
        $res->req = $req;
        $res->a = 'answer';

        $out = StringRedactor::redact(new Event(parameters: $req), $this->mask());

        $this->assertInstanceOf(\stdClass::class, $out->parameters);
        $outRes = $out->parameters->res;
        $this->assertInstanceOf(\stdClass::class, $outRes);
        $this->assertSame($out->parameters, $outRes->req);
        $this->assertSame('[REDACTED-6]', $out->parameters->q);
        $this->assertSame('[REDACTED-6]', $outRes->a);

        $shared = new \stdClass();
        $shared->s = 'abc';
        $holder = new \stdClass();
        $holder->a = $shared;
        $holder->b = $shared;
        $calls = 0;
        $counted = StringRedactor::redact(
            new Event(parameters: $holder),
            static function (string $text) use (&$calls): string {
                ++$calls;

                return 'R';
            },
        );

        $this->assertInstanceOf(\stdClass::class, $counted->parameters);
        $this->assertTrue(property_exists($counted->parameters, 'a'));
        $this->assertSame($counted->parameters->a, $counted->parameters->b);
        $this->assertSame(1, $calls);
    }

    #[Group('ac-05-R12')]
    public function testInputIsNotMutated(): void
    {
        $inner = new \stdClass();
        $inner->text = 'abc';
        $event = new Event(parameters: ['inner' => $inner], userIntent: 'intent');
        $before = serialize($event);

        $out = StringRedactor::redact($event, $this->mask());

        $this->assertSame($before, serialize($event));
        $this->assertNotSame($before, serialize($out));
        $this->assertNotSame($event, $out);
    }

    #[Group('ac-05-R13')]
    public function testRedactorExceptionPropagates(): void
    {
        $e = Thrown::by(\RuntimeException::class, fn(): mixed => StringRedactor::redact(new Event(userIntent: 'x'), static fn(string $text): string => throw new \RuntimeException('Redaction failed')));
        $this->assertStringContainsString('Redaction failed', $e->getMessage());
    }

    #[Group('ac-05-R14')]
    public function testNonStringResultIsRejected(): void
    {
        // @phpstan-ignore argument.type (deliberately violates the redactor contract)
        $e = Thrown::by(\UnexpectedValueException::class, fn(): mixed => StringRedactor::redact(new Event(userIntent: 'x'), static fn(string $text): int => 5));

        $this->assertStringContainsString('redactSensitiveInformation must return a string, got int', $e->getMessage());
    }

    public function testObjectsBecomeStdClassAndEnumsAreKept(): void
    {
        $out = StringRedactor::redact(
            new Event(
                parameters: new PlainThing(),
                response: new SerializableThing(),
                error: UnitStatus::Active,
            ),
            $this->mask(),
        );

        $this->assertEquals(Json::decode('{"visible":"[REDACTED-1]","next":null}'), $out->parameters);
        $this->assertInstanceOf(\stdClass::class, $out->parameters);
        $this->assertEquals(['kind' => '[REDACTED-5]', 'size' => 2], $out->response);
        $this->assertSame(UnitStatus::Active, $out->error);
    }

    public function testResourcesAreKept(): void
    {
        $stream = fopen('php://memory', 'r');
        $this->assertNotFalse($stream);

        $out = StringRedactor::redact(new Event(parameters: [$stream]), $this->mask());

        $this->assertSame([$stream], $out->parameters);
        fclose($stream);
    }

    public function testDepthLimit(): void
    {
        $cyclic = [];
        $cyclic['self'] = &$cyclic;

        $e = Thrown::by(\RangeException::class, fn(): mixed => StringRedactor::redact(new Event(parameters: $cyclic), $this->mask()));
        $this->assertStringContainsString('Maximum call stack size exceeded', $e->getMessage());
    }

    public function testDepthLimitIsExactlyFiveHundredTwelve(): void
    {
        $nested = 'leaf';
        for ($i = 0; $i < 512; ++$i) {
            $nested = [$nested];
        }

        $out = StringRedactor::redact(new Event(parameters: $nested), $this->mask());

        $this->assertIsArray($out->parameters);

        $this->expectException(\RangeException::class);
        StringRedactor::redact(new Event(parameters: [$nested]), $this->mask());
    }

    public function testDepthLimitCountsObjectLevels(): void
    {
        $build = static function (int $levels): \stdClass {
            $node = new \stdClass();
            $node->leaf = 'leaf';
            for ($i = 1; $i < $levels; ++$i) {
                $wrapper = new \stdClass();
                $wrapper->child = $node;
                $node = $wrapper;
            }

            return $node;
        };

        $out = StringRedactor::redact(new Event(parameters: $build(512)), $this->mask());
        $this->assertInstanceOf(\stdClass::class, $out->parameters);

        $this->expectException(\RangeException::class);
        StringRedactor::redact(new Event(parameters: $build(513)), $this->mask());
    }

    public function testDepthLimitCountsJsonSerializableLevels(): void
    {
        $build = static function (int $levels): mixed {
            $node = 'leaf';
            for ($i = 0; $i < $levels; ++$i) {
                $node = new readonly class ($node) implements \JsonSerializable {
                    public function __construct(private mixed $inner) {}

                    #[\Override]
                    public function jsonSerialize(): mixed
                    {
                        return $this->inner;
                    }
                };
            }

            return $node;
        };

        $out = StringRedactor::redact(new Event(parameters: $build(512)), $this->mask());
        $this->assertSame('[REDACTED-4]', $out->parameters);

        $this->expectException(\RangeException::class);
        StringRedactor::redact(new Event(parameters: $build(513)), $this->mask());
    }

    public function testSelfReturningJsonSerializableHitsTheDepthLimit(): void
    {
        $this->expectException(\RangeException::class);

        StringRedactor::redact(new Event(parameters: new SerializableThing(returnSelf: true)), $this->mask());
    }

    public function testNonPublicPropertiesOfAnArrayCastObjectAreSkipped(): void
    {
        $out = StringRedactor::redact(new Event(parameters: (object) (array) new VisibilityThing()), $this->mask());

        $this->assertSame(['name'], array_keys((array) $out->parameters));
        $this->assertEquals(Json::decode('{"name":"[REDACTED-1]"}'), $out->parameters);
    }

    public function testIntegerObjectKeysBecomeStringProperties(): void
    {
        $out = StringRedactor::redact(new Event(parameters: (object) [1 => 'x']), $this->mask());

        $this->assertEquals(Json::decode('{"1":"[REDACTED-1]"}'), $out->parameters);
    }

    public function testProtectedFieldList(): void
    {
        // @phpstan-ignore method.alreadyNarrowedType (pins the verbatim constant)
        $this->assertSame(
            ['sessionId', 'id', 'projectId', 'server', 'serverName', 'serverVersion', 'clientName', 'clientVersion', 'identifyActorGivenId', 'identifyActorName', 'identifyData', 'resourceName', 'eventType', 'actorId', 'tags', 'properties'],
            StringRedactor::PROTECTED_FIELDS,
        );
    }
}
