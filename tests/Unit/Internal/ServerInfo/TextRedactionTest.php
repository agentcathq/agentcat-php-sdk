<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\ServerInfo;

use AgentCat\Internal\ServerInfo\ServerInfoBody;
use AgentCat\Internal\ServerInfo\ServerInfoSource;
use AgentCat\Internal\ServerInfo\Skipped;
use AgentCat\Internal\ServerInfo\TextRedaction;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\TestCase;

final class TextRedactionTest extends TestCase
{
    public function testV11TheRedactorRunsOnEveryTextAndOnNoName(): void
    {
        $body = $this->body();
        $seen = [];
        $redactor = static function (string $text) use (&$seen): string {
            $seen[] = $text;

            return str_replace('secret', '[x]', $text);
        };

        $redacted = TextRedaction::apply($body, $redactor, static function (): void {});

        $this->assertSame('the [x] plan', $redacted->instructions);
        $this->assertSame('[x] title', $redacted->title);
        $tool = JsonAssert::at($redacted->features, ServerInfoSource::TOOLS, 0);
        $this->assertSame('secret_tool', JsonAssert::at($tool, 'name'));
        $this->assertSame('[x] tool', JsonAssert::at($tool, 'description'));
        $this->assertSame('a [x]', JsonAssert::at($tool, 'inputSchema', 'properties', 'secret', 'description'), 'a key is never changed');
        $this->assertSame(['[x] tag', 7, true, null], JsonAssert::at($tool, 'annotations', 'tags'));
        $this->assertSame('[x] name inside', JsonAssert::at($tool, 'annotations', 'name'), 'only the name at the top of an item is kept');
        $resource = JsonAssert::at($redacted->features, ServerInfoSource::RESOURCES, 0);
        $this->assertSame(['file:///secret.txt', 'secret', 'the [x] file'], [JsonAssert::at($resource, 'uri'), JsonAssert::at($resource, 'name'), JsonAssert::at($resource, 'description')]);
        $template = JsonAssert::at($redacted->features, ServerInfoSource::RESOURCE_TEMPLATES, 0);
        $this->assertSame(['file:///{secret}', '[x] template'], [JsonAssert::at($template, 'uriTemplate'), JsonAssert::at($template, 'title')]);
        $this->assertSame(['secret-server', '1.0-secret', 'proj_secret'], [$redacted->serverName, $redacted->serverVersion, $redacted->projectId]);
        $this->assertSame('a bare [x]', JsonAssert::at($redacted->features, ServerInfoSource::PROMPTS, 0), 'an item that is no object is text');
        $this->assertNotContains('secret_tool', $seen);
        $this->assertNotContains('file:///secret.txt', $seen);
        $this->assertNotContains('secret-server', $seen);
        $this->assertContains('object', $seen, 'a string value of a schema goes through the redactor like any other');
    }

    public function testTheBodyThatGoesInIsNotChanged(): void
    {
        $body = $this->body();
        $before = $body->encode();

        TextRedaction::apply($body, static fn(string $text): string => strtoupper($text), static function (): void {});

        $this->assertSame($before, $body->encode());
    }

    public function testV12ARedactorThatThrowsStopsTheRedaction(): void
    {
        $this->expectException(\DomainException::class);
        TextRedaction::apply($this->body(), static fn(string $text): string => throw new \DomainException('redactor down'), static function (): void {});
    }

    public function testV12ARedactorThatGivesNoStringStopsTheRedaction(): void
    {
        $this->expectException(Skipped::class);
        $this->expectExceptionMessage('redactSensitiveInformation must return a string, got null');
        // @phpstan-ignore argument.type (a customer callback can return any type at runtime)
        TextRedaction::apply($this->body(), static fn(string $text): mixed => null, static function (): void {});
    }

    public function testTheCheckRunsBeforeEachTextSoATimeLimitStopsTheRedaction(): void
    {
        $calls = new \ArrayObject();

        $skipped = Thrown::by(Skipped::class, fn(): ServerInfoBody => TextRedaction::apply($this->body(), static function (string $text) use ($calls): string {
            $calls->append($text);

            return $text;
        }, static function () use ($calls): void {
            if (count($calls) === 2) {
                throw new Skipped('out of time');
            }
        }));

        $this->assertSame('out of time', $skipped->getMessage());
        $this->assertSame(['secret title', 'the secret plan'], $calls->getArrayCopy());
    }

    private function body(): ServerInfoBody
    {
        return new ServerInfoBody('proj_secret', 'secret-server', '1.0-secret', 'secret title', 'the secret plan', [
            ServerInfoSource::TOOLS => [(object) [
                'name' => 'secret_tool',
                'description' => 'secret tool',
                'inputSchema' => (object) ['type' => 'object', 'properties' => (object) ['secret' => (object) ['type' => 'string', 'description' => 'a secret']]],
                'annotations' => (object) ['tags' => ['secret tag', 7, true, null], 'name' => 'secret name inside'],
            ]],
            ServerInfoSource::RESOURCES => [(object) ['uri' => 'file:///secret.txt', 'name' => 'secret', 'description' => 'the secret file']],
            ServerInfoSource::RESOURCE_TEMPLATES => [(object) ['uriTemplate' => 'file:///{secret}', 'name' => 'files', 'title' => 'secret template']],
            ServerInfoSource::PROMPTS => ['a bare secret'],
        ]);
    }
}
