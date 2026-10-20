<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\Snapshot;

/**
 * The difference between two member snapshots of one library. A line is `breaking` when it removes or changes a
 * member AgentCat reads (a private property, or a method of a class AgentCat extends) or when a class AgentCat extends
 * turns final; every other difference is `other`. The members AgentCat reads come from PrivateMemberManifest::members().
 *
 * @phpstan-import-type Snapshot from MemberSnapshot
 * @phpstan-import-type ClassShape from MemberSnapshot
 */
final readonly class SnapshotDiff
{
    /**
     * @param list<string> $breaking
     * @param list<string> $other
     */
    private function __construct(
        private array $breaking,
        private array $other,
    ) {}

    /**
     * @param Snapshot $old
     * @param Snapshot $new
     * @param list<string> $readMembers `Class::$property` and `Class::method(...)`; any other string is ignored
     */
    public static function between(array $old, array $new, array $readMembers): self
    {
        $properties = [];
        $methods = [];
        foreach ($readMembers as $member) {
            if (preg_match('/^([A-Za-z0-9_\\\\]+)::\$(\w+)$/', $member, $match) === 1) {
                $properties[$match[1]][$match[2]] = true;
            } elseif (preg_match('/^([A-Za-z0-9_\\\\]+)::(\w+)\(/', $member, $match) === 1) {
                $methods[$match[1]][$match[2]] = true;
            }
        }

        $breaking = [];
        $other = [];
        $classes = array_unique([...array_keys($old['classes']), ...array_keys($new['classes'])]);
        sort($classes, SORT_STRING);
        foreach ($classes as $class) {
            $oldClass = $old['classes'][$class] ?? null;
            $newClass = $new['classes'][$class] ?? null;
            $readProperties = array_keys($properties[$class] ?? []);
            $readMethods = array_keys($methods[$class] ?? []);

            if ($newClass === null) {
                $other[] = $class . ': class removed';
                foreach ($readProperties as $name) {
                    if (isset($oldClass['properties'][$name])) {
                        $breaking[] = $class . '::$' . $name . ': ' . self::property($name, $oldClass['properties'][$name]) . ' → removed';
                    }
                }
                foreach ($readMethods as $name) {
                    if (isset($oldClass['methods'][$name])) {
                        $breaking[] = $class . '::' . $name . '(): ' . self::method($name, $oldClass['methods'][$name]) . ' → removed';
                    }
                }

                continue;
            }
            if ($oldClass === null) {
                $other[] = $class . ': class added';

                continue;
            }

            if ($oldClass['final'] !== $newClass['final']) {
                $line = $class . ': ' . ($oldClass['final'] ? 'final' : 'not final') . ' → ' . ($newClass['final'] ? 'final' : 'not final');
                if ($newClass['final'] && $readMethods !== []) {
                    $breaking[] = $line;
                } else {
                    $other[] = $line;
                }
            }

            self::compareProperties($class, $oldClass, $newClass, $readProperties, $breaking, $other);
            self::compareMethods($class, $oldClass, $newClass, $readMethods, $breaking, $other);
            self::compareConstants($class, $oldClass, $newClass, $other);
        }

        sort($breaking, SORT_STRING);
        sort($other, SORT_STRING);

        return new self($breaking, $other);
    }

    /** @return list<string> the changes that break AgentCat, each naming `Class::member` and old → new */
    public function breaking(): array
    {
        return $this->breaking;
    }

    /** @return list<string> every other difference */
    public function other(): array
    {
        return $this->other;
    }

    /**
     * @param ClassShape $old
     * @param ClassShape $new
     * @param list<string> $read
     * @param list<string> $breaking
     * @param list<string> $other
     */
    private static function compareProperties(string $class, array $old, array $new, array $read, array &$breaking, array &$other): void
    {
        $added = array_diff_key($new['properties'], $old['properties']);
        foreach ($old['properties'] as $name => $oldShape) {
            $subject = $class . '::$' . $name;
            $isRead = in_array($name, $read, true);
            $newShape = $new['properties'][$name] ?? null;
            if ($newShape === $oldShape) {
                continue;
            }
            if ($newShape === null) {
                $renamedTo = array_search($oldShape, $added, true);
                if ($renamedTo !== false) {
                    unset($added[$renamedTo]);
                    $line = $subject . ': renamed to $' . $renamedTo;
                } else {
                    $line = $subject . ': ' . self::property($name, $oldShape) . ' → removed';
                }
            } else {
                $line = $subject . ': ' . self::property($name, $oldShape) . ' → ' . self::property($name, $newShape);
            }
            if ($isRead) {
                $breaking[] = $line;
            } else {
                $other[] = $line;
            }
        }
        foreach ($added as $name => $shape) {
            $other[] = $class . '::$' . $name . ': added (' . self::property($name, $shape) . ')';
        }
    }

    /**
     * @param ClassShape $old
     * @param ClassShape $new
     * @param list<string> $read
     * @param list<string> $breaking
     * @param list<string> $other
     */
    private static function compareMethods(string $class, array $old, array $new, array $read, array &$breaking, array &$other): void
    {
        foreach ($old['methods'] as $name => $oldShape) {
            $newShape = $new['methods'][$name] ?? null;
            if ($newShape === $oldShape) {
                continue;
            }
            $line = $class . '::' . $name . '(): ' . self::method($name, $oldShape) . ' → ' . ($newShape === null ? 'removed' : self::method($name, $newShape));
            if (in_array($name, $read, true)) {
                $breaking[] = $line;
            } else {
                $other[] = $line;
            }
        }
        foreach (array_diff_key($new['methods'], $old['methods']) as $name => $shape) {
            $other[] = $class . '::' . $name . '(): added (' . self::method($name, $shape) . ')';
        }
    }

    /**
     * @param ClassShape $old
     * @param ClassShape $new
     * @param list<string> $other
     */
    private static function compareConstants(string $class, array $old, array $new, array &$other): void
    {
        foreach ($old['constants'] as $name => $shape) {
            $newShape = $new['constants'][$name] ?? null;
            if ($newShape !== $shape) {
                $other[] = $class . '::' . $name . ': ' . $shape['visibility'] . ' → ' . ($newShape === null ? 'removed' : $newShape['visibility']);
            }
        }
        foreach (array_diff_key($new['constants'], $old['constants']) as $name => $shape) {
            $other[] = $class . '::' . $name . ': added (' . $shape['visibility'] . ')';
        }
    }

    /** @param array{readonly: bool, static: bool, type: string, visibility: string} $shape */
    private static function property(string $name, array $shape): string
    {
        return self::words([$shape['visibility'], $shape['static'] ? 'static' : '', $shape['readonly'] ? 'readonly' : '', $shape['type'], '$' . $name]);
    }

    /** @param array{abstract: bool, final: bool, parameters: list<array{byReference: bool, hasDefault: bool, name: string, type: string, variadic: bool}>, return: string, static: bool, visibility: string} $shape */
    private static function method(string $name, array $shape): string
    {
        $parameters = [];
        foreach ($shape['parameters'] as $parameter) {
            $parameters[] = self::words([
                $parameter['type'],
                ($parameter['byReference'] ? '&' : '') . ($parameter['variadic'] ? '...' : '') . '$' . $parameter['name'] . ($parameter['hasDefault'] ? ' = …' : ''),
            ]);
        }

        return self::words([$shape['visibility'], $shape['abstract'] ? 'abstract' : '', $shape['final'] ? 'final' : '', $shape['static'] ? 'static' : '', 'function'])
            . ' ' . $name . '(' . implode(', ', $parameters) . ')' . ($shape['return'] === '' ? '' : ': ' . $shape['return']);
    }

    /** @param list<string> $words */
    private static function words(array $words): string
    {
        return implode(' ', array_filter($words, static fn(string $word): bool => $word !== ''));
    }
}
