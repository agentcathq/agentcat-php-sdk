<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Tools;

use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Tools\GetMoreTools;
use AgentCat\Tests\Support\Golden;
use AgentCat\Tests\Support\MemoryLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class GetMoreToolsTest extends TestCase
{
    protected function tearDown(): void
    {
        Log::reset();
    }

    #[Group('ac-04-45')]
    public function testDescriptorMatchesGolden(): void
    {
        $fixture = Golden::json('handles/strings.json');
        $this->assertInstanceOf(\stdClass::class, $fixture);
        $this->assertSame(Json::encodeJs($fixture->GET_MORE_TOOLS_DESCRIPTOR), Json::encodeJs(GetMoreTools::descriptor()));
    }

    #[Group('ac-04-45')]
    public function testDescriptorNestsObjects(): void
    {
        $descriptor = GetMoreTools::descriptor();
        $schema = $descriptor->inputSchema;
        $this->assertInstanceOf(\stdClass::class, $schema);
        $this->assertInstanceOf(\stdClass::class, $schema->properties);
        $this->assertInstanceOf(\stdClass::class, $schema->properties->context);
        $this->assertInstanceOf(\stdClass::class, $descriptor->annotations);
    }

    #[Group('ac-04-45')]
    public function testDescriptorIsFreshPerCall(): void
    {
        $first = GetMoreTools::descriptor();
        $first->name = 'changed';
        $firstSchema = $first->inputSchema;
        $this->assertInstanceOf(\stdClass::class, $firstSchema);
        $firstSchema->required = [];

        $second = GetMoreTools::descriptor();
        $this->assertSame(GetMoreTools::NAME, $second->name);
        $secondSchema = $second->inputSchema;
        $this->assertInstanceOf(\stdClass::class, $secondSchema);
        $this->assertSame(['context'], $secondSchema->required);
    }

    #[Group('ac-04-47')]
    public function testResultMatchesGolden(): void
    {
        Log::setLogger(new MemoryLogger());
        $fixture = Golden::json('handles/strings.json');
        $this->assertInstanceOf(\stdClass::class, $fixture);
        $result = GetMoreTools::result(null);

        $this->assertSame(Json::encodeJs($fixture->GET_MORE_TOOLS_RESULT), Json::encodeJs($result));
        $this->assertIsArray($result->content);
        $this->assertArrayHasKey(0, $result->content);
        $this->assertInstanceOf(\stdClass::class, $result->content[0]);
        $this->assertObjectNotHasProperty('isError', $result);
        $this->assertObjectNotHasProperty('structuredContent', $result);
    }

    #[Group('ac-04-47')]
    public function testLogsUtf16LengthOfContext(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);

        GetMoreTools::result(Json::decode('{"context":"abc😀"}'));

        $this->assertSame(['Missing tool reported (context length: 5)'], $logger->messages());
    }

    #[Group('ac-04-47')]
    public function testLogsZeroForMissingOrNonStringContext(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);

        GetMoreTools::result(null);
        GetMoreTools::result(Json::decode('{"context":5}'));
        GetMoreTools::result(Json::decode('{}'));
        GetMoreTools::result('context');

        $this->assertSame(array_fill(0, 4, 'Missing tool reported (context length: 0)'), $logger->messages());
    }
}
