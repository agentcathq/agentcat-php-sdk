<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit;

use AgentCat\Event;
use AgentCat\Identity;
use AgentCat\Internal\Logging\Log;
use AgentCat\Options;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\ToolCall;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    private MemoryLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    #[Group('ac-01-13')]
    public function testDefaults(): void
    {
        $options = new Options();

        $this->assertTrue($options->enableReportMissing);
        $this->assertTrue($options->enableTracing);
        $this->assertTrue($options->enableToolCallContext);
        $this->assertFalse($options->enableAgentTracking);
        $this->assertNull($options->customContextDescription);
        $this->assertNull($options->resolveSessionId);
        $this->assertNull($options->identify);
        $this->assertNull($options->redactSensitiveInformation);
        $this->assertNull($options->redactEvent);
        $this->assertNull($options->apiBaseUrl);
        $this->assertNull($options->eventTags);
        $this->assertNull($options->eventProperties);
    }

    public function testNamedArgumentsAreKept(): void
    {
        $options = new Options(enableAgentTracking: true, apiBaseUrl: 'https://a.test');

        $this->assertTrue($options->enableAgentTracking);
        $this->assertSame('https://a.test', $options->apiBaseUrl);
    }

    public function testClassIsReadonly(): void
    {
        $this->assertTrue((new \ReflectionClass(Options::class))->isReadOnly());
    }

    public function testFromArrayAcceptsValidValuesAndCallables(): void
    {
        $handler = new class {
            public function __invoke(ToolCall $call): ?Identity
            {
                return $call->toolName === 'known' ? new Identity('u') : null;
            }
        };

        $options = Options::fromArray([
            'enableTracing' => false,
            'customContextDescription' => 'X',
            'identify' => $handler,
        ]);

        $this->assertFalse($options->enableTracing);
        $this->assertSame('X', $options->customContextDescription);
        $this->assertInstanceOf(\Closure::class, $options->identify);
        $this->assertNull(($options->identify)(new ToolCall('t', [])));
        $this->assertEquals(new Identity('u'), ($options->identify)(new ToolCall('known', [])));
        $this->assertSame([], $this->logger->messages());
    }

    public function testFromArrayAcceptsEveryOptionWithAValidValue(): void
    {
        $resolveSessionId = static fn(ToolCall $call): ?string => null;
        $identify = static fn(ToolCall $call): ?Identity => null;
        $redactSensitiveInformation = static fn(string $text): string => $text;
        $redactEvent = static fn(Event $event): Event => $event;
        $eventTags = static fn(ToolCall $call): array => ['k' => 'v'];
        $eventProperties = static fn(ToolCall $call): mixed => ['p' => 1];

        $options = Options::fromArray([
            'enableReportMissing' => false,
            'enableTracing' => false,
            'enableToolCallContext' => false,
            'customContextDescription' => 'Why you call this tool',
            'enableAgentTracking' => true,
            'resolveSessionId' => $resolveSessionId,
            'identify' => $identify,
            'redactSensitiveInformation' => $redactSensitiveInformation,
            'redactEvent' => $redactEvent,
            'apiBaseUrl' => 'https://api.example.test',
            'eventTags' => $eventTags,
            'eventProperties' => $eventProperties,
        ]);

        $this->assertFalse($options->enableReportMissing);
        $this->assertFalse($options->enableTracing);
        $this->assertFalse($options->enableToolCallContext);
        $this->assertSame('Why you call this tool', $options->customContextDescription);
        $this->assertTrue($options->enableAgentTracking);
        $this->assertSame($resolveSessionId, $options->resolveSessionId);
        $this->assertSame($identify, $options->identify);
        $this->assertSame($redactSensitiveInformation, $options->redactSensitiveInformation);
        $this->assertSame($redactEvent, $options->redactEvent);
        $this->assertSame('https://api.example.test', $options->apiBaseUrl);
        $this->assertSame($eventTags, $options->eventTags);
        $this->assertSame($eventProperties, $options->eventProperties);
        $this->assertSame([], $this->logger->messages());
    }

    public function testFromArrayKeepsAClosureAsIs(): void
    {
        $closure = static fn(string $text): string => $text;

        $this->assertSame($closure, Options::fromArray(['redactSensitiveInformation' => $closure])->redactSensitiveInformation);
    }

    public function testFromArrayAcceptsExplicitNulls(): void
    {
        $options = Options::fromArray(['apiBaseUrl' => null, 'identify' => null, 'customContextDescription' => null]);

        $this->assertNull($options->apiBaseUrl);
        $this->assertNull($options->identify);
        $this->assertSame([], $this->logger->messages());
    }

    public function testFromArrayWarnsAndUsesDefaults(): void
    {
        $options = Options::fromArray([
            'bogus' => 1,
            'enableAgentTracking' => 'yes',
            'apiBaseUrl' => 5,
            'redactEvent' => 'not a function',
        ]);

        $this->assertFalse($options->enableAgentTracking);
        $this->assertNull($options->apiBaseUrl);
        $this->assertNull($options->redactEvent);
        $this->assertSame([
            'WARN: Unknown AgentCat option "bogus" ignored',
            'WARN: AgentCat option "enableAgentTracking" must be bool, got string; the default is used',
            'WARN: AgentCat option "redactEvent" must be callable or null, got string; the default is used',
            'WARN: AgentCat option "apiBaseUrl" must be string or null, got int; the default is used',
        ], $this->logger->messages());
    }

    public function testFromArrayRejectsAWrongBoolWithItsDefault(): void
    {
        $options = Options::fromArray(['enableTracing' => 0]);

        $this->assertTrue($options->enableTracing);
        $this->assertSame(
            ['WARN: AgentCat option "enableTracing" must be bool, got int; the default is used'],
            $this->logger->messages(),
        );
    }

    public function testFromArrayCastsIntegerKeysToStrings(): void
    {
        Options::fromArray([0 => true]);

        $this->assertSame(['WARN: Unknown AgentCat option "0" ignored'], $this->logger->messages());
    }

    public function testFromArrayWithNoKeysEqualsDefaults(): void
    {
        $this->assertEquals(new Options(), Options::fromArray([]));
        $this->assertSame([], $this->logger->messages());
    }
}
