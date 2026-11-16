<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Internal\ServerInfo\ServerInfoSource;

/**
 * A server for the heartbeat without an MCP library. Each list is its pages by cursor (`''` is the first page), a
 * closure that answers a cursor, or a throwable that every page throws; a list that is not set has no handler.
 */
final class FakeServerInfo implements ServerInfoSource
{
    /** @var list<string> every page asked for, as `<list>@<cursor>` */
    public array $asked = [];

    /**
     * @param array<string, array<string, array{list<mixed>, ?string}>|\Closure(?string): (array{list<mixed>, ?string}|null)|\Throwable>|null $lists null: the demo server
     */
    public function __construct(
        private ?array $lists = null,
        private readonly string|\Throwable $title = 'Demo Title',
        private readonly string $instructions = 'Use the demo tools.',
        private readonly bool $advertised = false,
        public ?string $name = 'demo-server',
        public ?string $version = '1.2.3',
    ) {
        $this->lists ??= self::demo();
    }

    /**
     * The demo server: three tools (`beta` with a 2,000-character description, both schemas, a title and annotations),
     * two resources, one resource template and two prompts, each list on one page.
     *
     * @return array<string, array<string, array{list<mixed>, ?string}>>
     */
    public static function demo(): array
    {
        return [
            self::TOOLS => ['' => [[self::tool('alpha'), self::beta(), self::tool('gamma')], null]],
            self::RESOURCES => ['' => [[
                (object) ['uri' => 'file:///a.txt', 'name' => 'a', 'description' => 'File a'],
                (object) ['uri' => 'file:///b.txt', 'name' => 'b'],
            ], null]],
            self::RESOURCE_TEMPLATES => ['' => [[(object) ['uriTemplate' => 'file:///{name}.txt', 'name' => 'files', 'description' => 'Any file']], null]],
            self::PROMPTS => ['' => [[
                (object) ['name' => 'greet', 'description' => 'Greets', 'arguments' => [(object) ['name' => 'who', 'description' => 'Whom to greet', 'required' => true]]],
                (object) ['name' => 'bye'],
            ], null]],
        ];
    }

    public static function tool(string $name, string $description = 'A tool'): \stdClass
    {
        return (object) ['name' => $name, 'description' => $description, 'inputSchema' => (object) ['type' => 'object', 'properties' => (object) ['q' => (object) ['type' => 'string', 'description' => 'The query']]]];
    }

    public static function beta(): \stdClass
    {
        return (object) [
            'name' => 'beta',
            'title' => 'Beta Tool',
            'description' => str_repeat('b', 2000),
            'inputSchema' => (object) ['type' => 'object', 'properties' => (object) ['n' => (object) ['type' => 'integer']]],
            'outputSchema' => (object) ['type' => 'object', 'properties' => (object) ['sum' => (object) ['type' => 'integer']]],
            'annotations' => (object) ['readOnlyHint' => true],
        ];
    }

    #[\Override]
    public function server(): array
    {
        return [$this->name, $this->version];
    }

    #[\Override]
    public function title(): string
    {
        return $this->title instanceof \Throwable ? throw $this->title : $this->title;
    }

    #[\Override]
    public function instructions(): string
    {
        return $this->instructions;
    }

    #[\Override]
    public function toolsAdvertised(): bool
    {
        return $this->advertised;
    }

    #[\Override]
    public function page(string $list, ?string $cursor): ?array
    {
        $this->asked[] = $list . '@' . ($cursor ?? '');
        $pages = $this->lists[$list] ?? null;
        if ($pages === null) {
            return null;
        }
        if ($pages instanceof \Throwable) {
            throw $pages;
        }
        if ($pages instanceof \Closure) {
            return $pages($cursor);
        }

        return $pages[$cursor ?? ''] ?? throw new \OutOfBoundsException('no page at cursor ' . ($cursor ?? ''));
    }
}
