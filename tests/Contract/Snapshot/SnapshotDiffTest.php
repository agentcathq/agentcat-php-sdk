<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\Snapshot;

use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Snapshot from MemberSnapshot
 * @phpstan-import-type ClassShape from MemberSnapshot
 */
final class SnapshotDiffTest extends TestCase
{
    private const string BUILDER = \Mcp\Server\Builder::class;
    private const string RESULT = \Mcp\Schema\Result\ListToolsResult::class;
    private const string PARTS = 'Mcp\\Server\\Builder::$parts';
    private const string CONSTRUCTOR = 'Mcp\\Schema\\Result\\ListToolsResult::__construct(array $tools, ?string $nextCursor = null)';

    public function testARenamedMemberIsNamed(): void
    {
        $old = $this->snapshot([self::BUILDER => $this->shape(properties: ['parts' => $this->property('array')])]);
        $new = $this->snapshot([self::BUILDER => $this->shape(properties: ['partList' => $this->property('array')])]);

        $diff = SnapshotDiff::between($old, $new, [self::PARTS]);

        $this->assertSame(['Mcp\\Server\\Builder::$parts: renamed to $partList'], $diff->breaking());
        $this->assertSame([], $diff->other());
    }

    public function testARenamedMemberThatNoOneReadsIsNotBreaking(): void
    {
        $old = $this->snapshot([self::BUILDER => $this->shape(properties: ['parts' => $this->property('array')])]);
        $new = $this->snapshot([self::BUILDER => $this->shape(properties: ['partList' => $this->property('array')])]);

        $diff = SnapshotDiff::between($old, $new, []);

        $this->assertSame([], $diff->breaking());
        $this->assertSame(['Mcp\\Server\\Builder::$parts: renamed to $partList'], $diff->other());
    }

    public function testARemovedOrRetypedMemberIsBreaking(): void
    {
        $old = $this->snapshot([self::BUILDER => $this->shape(properties: ['parts' => $this->property('array')])]);
        $retyped = $this->snapshot([self::BUILDER => $this->shape(properties: ['parts' => $this->property('?array')])]);
        $removed = $this->snapshot([self::BUILDER => $this->shape()]);

        $this->assertSame(
            ['Mcp\\Server\\Builder::$parts: private array $parts → private ?array $parts'],
            SnapshotDiff::between($old, $retyped, [self::PARTS])->breaking(),
        );
        $this->assertSame(
            ['Mcp\\Server\\Builder::$parts: private array $parts → removed'],
            SnapshotDiff::between($old, $removed, [self::PARTS])->breaking(),
        );
    }

    public function testARemovedClassBreaksTheMembersAgentCatReads(): void
    {
        $old = $this->snapshot([self::BUILDER => $this->shape(properties: ['parts' => $this->property('array')])]);

        $diff = SnapshotDiff::between($old, $this->snapshot([]), [self::PARTS]);

        $this->assertSame(['Mcp\\Server\\Builder::$parts: private array $parts → removed'], $diff->breaking());
        $this->assertSame(['Mcp\\Server\\Builder: class removed'], $diff->other());
    }

    public function testAnAddedMethodIsNotBreaking(): void
    {
        $old = $this->snapshot([self::BUILDER => $this->shape(properties: ['parts' => $this->property('array')])]);
        $new = $this->snapshot([self::BUILDER => $this->shape(properties: ['parts' => $this->property('array')], methods: ['build' => $this->method([], \Mcp\Server::class)])]);

        $diff = SnapshotDiff::between($old, $new, [self::PARTS]);

        $this->assertSame([], $diff->breaking());
        $this->assertSame(['Mcp\\Server\\Builder::build(): added (public function build(): Mcp\\Server)'], $diff->other());
    }

    public function testAnAddedClassAndAChangedConstantAreNotBreaking(): void
    {
        $old = $this->snapshot([self::BUILDER => $this->shape(constants: ['A' => 'public', 'B' => 'public'])]);
        $new = $this->snapshot([
            self::BUILDER => $this->shape(constants: ['A' => 'protected', 'C' => 'public']),
            self::RESULT => $this->shape(),
        ]);

        $diff = SnapshotDiff::between($old, $new, [self::PARTS, self::CONSTRUCTOR]);

        $this->assertSame([], $diff->breaking());
        $this->assertSame(
            [
                'Mcp\\Schema\\Result\\ListToolsResult: class added',
                'Mcp\\Server\\Builder::A: public → protected',
                'Mcp\\Server\\Builder::B: public → removed',
                'Mcp\\Server\\Builder::C: added (public)',
            ],
            $diff->other(),
        );
    }

    public function testAFinalParentIsBreaking(): void
    {
        $old = $this->snapshot([self::RESULT => $this->shape(methods: ['__construct' => $this->method(['tools' => 'array', 'nextCursor' => '?string'], '')])]);
        $new = $this->snapshot([self::RESULT => $this->shape(final: true, methods: ['__construct' => $this->method(['tools' => 'array', 'nextCursor' => '?string'], '')])]);

        $diff = SnapshotDiff::between($old, $new, [self::CONSTRUCTOR]);

        $this->assertSame(['Mcp\\Schema\\Result\\ListToolsResult: not final → final'], $diff->breaking());
        $this->assertSame([], $diff->other());
    }

    public function testAClassNobodyExtendsTurningFinalIsNotBreaking(): void
    {
        $diff = SnapshotDiff::between(
            $this->snapshot([self::BUILDER => $this->shape()]),
            $this->snapshot([self::BUILDER => $this->shape(final: true)]),
            [self::PARTS, self::CONSTRUCTOR],
        );

        $this->assertSame([], $diff->breaking());
        $this->assertSame(['Mcp\\Server\\Builder: not final → final'], $diff->other());
    }

    public function testAChangedExtendedSignatureIsBreaking(): void
    {
        $old = $this->snapshot([self::RESULT => $this->shape(methods: ['__construct' => $this->method(['tools' => 'array', 'nextCursor' => '?string'], '')])]);
        $new = $this->snapshot([self::RESULT => $this->shape(methods: ['__construct' => $this->method(['tools' => 'array', 'nextCursor' => '?string', 'limit' => 'int'], '')])]);

        $diff = SnapshotDiff::between($old, $new, [self::CONSTRUCTOR]);

        $this->assertSame(
            ['Mcp\\Schema\\Result\\ListToolsResult::__construct(): public function __construct(array $tools, ?string $nextCursor) → public function __construct(array $tools, ?string $nextCursor, int $limit)'],
            $diff->breaking(),
        );
    }

    public function testAChangedMethodNobodyDependsOnIsNotBreaking(): void
    {
        $old = $this->snapshot([self::RESULT => $this->shape(methods: ['jsonSerialize' => $this->method([], 'array')])]);
        $new = $this->snapshot([self::RESULT => $this->shape(methods: ['jsonSerialize' => $this->method([], 'mixed')])]);

        $diff = SnapshotDiff::between($old, $new, [self::CONSTRUCTOR]);

        $this->assertSame([], $diff->breaking());
        $this->assertSame(['Mcp\\Schema\\Result\\ListToolsResult::jsonSerialize(): public function jsonSerialize(): array → public function jsonSerialize(): mixed'], $diff->other());
    }

    public function testAnIdenticalSnapshotHasNoLinesAndTheBundleEntriesAreIgnored(): void
    {
        $snapshot = $this->snapshot([self::BUILDER => $this->shape(properties: ['parts' => $this->property('array')])]);

        $diff = SnapshotDiff::between($snapshot, $snapshot, [self::PARTS, 'symfony/mcp-bundle: builder without a configurator']);

        $this->assertSame([], $diff->breaking());
        $this->assertSame([], $diff->other());
    }

    public function testTheLinesAreSorted(): void
    {
        $old = $this->snapshot([self::BUILDER => $this->shape()]);
        $new = $this->snapshot([self::BUILDER => $this->shape(properties: ['b' => $this->property('int'), 'a' => $this->property('int')])]);

        $this->assertSame(
            ['Mcp\\Server\\Builder::$a: added (private int $a)', 'Mcp\\Server\\Builder::$b: added (private int $b)'],
            SnapshotDiff::between($old, $new, [])->other(),
        );
    }

    /**
     * @param array<string, ClassShape> $classes
     *
     * @return Snapshot
     */
    private function snapshot(array $classes): array
    {
        return ['classes' => $classes, 'library' => 'mcp/sdk', 'version' => '0.0.0'];
    }

    /**
     * @param array<string, array{readonly: bool, static: bool, type: string, visibility: string}> $properties
     * @param array<string, array{abstract: bool, final: bool, parameters: list<array{byReference: bool, hasDefault: bool, name: string, type: string, variadic: bool}>, return: string, static: bool, visibility: string}> $methods
     * @param array<string, string> $constants constant name => visibility
     *
     * @return ClassShape
     */
    private function shape(bool $final = false, array $properties = [], array $methods = [], array $constants = []): array
    {
        return [
            'constants' => array_map(static fn(string $visibility): array => ['visibility' => $visibility], $constants),
            'final' => $final,
            'methods' => $methods,
            'properties' => $properties,
        ];
    }

    /** @return array{readonly: bool, static: bool, type: string, visibility: string} */
    private function property(string $type): array
    {
        return ['readonly' => false, 'static' => false, 'type' => $type, 'visibility' => 'private'];
    }

    /**
     * @param array<string, string> $parameters type by name
     *
     * @return array{abstract: bool, final: bool, parameters: list<array{byReference: bool, hasDefault: bool, name: string, type: string, variadic: bool}>, return: string, static: bool, visibility: string}
     */
    private function method(array $parameters, string $return): array
    {
        $list = [];
        foreach ($parameters as $name => $type) {
            $list[] = ['byReference' => false, 'hasDefault' => false, 'name' => $name, 'type' => $type, 'variadic' => false];
        }

        return ['abstract' => false, 'final' => false, 'parameters' => $list, 'return' => $return, 'static' => false, 'visibility' => 'public'];
    }
}
