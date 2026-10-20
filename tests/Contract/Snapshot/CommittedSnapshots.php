<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\Snapshot;

/**
 * The snapshot files of a directory, named `<library with / as ->-<version>.json`.
 *
 * @phpstan-import-type Snapshot from MemberSnapshot
 */
final readonly class CommittedSnapshots
{
    public function __construct(
        private string $directory,
    ) {}

    public static function committed(): self
    {
        return new self(__DIR__ . '/../snapshots');
    }

    /** The file of exactly this version, or null. */
    public function exact(string $library, string $version): ?string
    {
        $path = $this->directory . '/' . $this->prefix($library) . $version . '.json';

        return is_file($path) ? $path : null;
    }

    /** The file of the highest version below this one, or null when there is none. */
    public function newestBelow(string $library, string $version): ?string
    {
        $newest = null;
        foreach ($this->versions($library) as $candidate) {
            if (version_compare($candidate, $version, '<') && ($newest === null || version_compare($candidate, $newest, '>'))) {
                $newest = $candidate;
            }
        }

        return $newest === null ? null : $this->directory . '/' . $this->prefix($library) . $newest . '.json';
    }

    /**
     * The live snapshot against the file of exactly this version, or, for a release without a file, against the
     * newest lower one. Null when there is no file at or below the version.
     *
     * @param Snapshot $live
     * @param list<string> $readMembers
     *
     * @return array{path: string, exact: bool, diff: SnapshotDiff}|null
     */
    public function compare(string $library, string $version, array $live, array $readMembers): ?array
    {
        $exact = $this->exact($library, $version);
        $path = $exact ?? $this->newestBelow($library, $version);
        if ($path === null) {
            return null;
        }

        return [
            'path' => $path,
            'exact' => $exact !== null,
            'diff' => SnapshotDiff::between(MemberSnapshot::fromJson((string) file_get_contents($path)), $live, $readMembers),
        ];
    }

    /** @return list<string> the path of every snapshot of the library */
    public function all(string $library): array
    {
        return array_map(
            fn(string $version): string => $this->directory . '/' . $this->prefix($library) . $version . '.json',
            $this->versions($library),
        );
    }

    /** @return list<string> */
    private function versions(string $library): array
    {
        $prefix = $this->prefix($library);
        $versions = [];
        $paths = glob($this->directory . '/' . $prefix . '*.json');
        foreach ($paths === false ? [] : $paths as $path) {
            $versions[] = substr(basename($path), strlen($prefix), -strlen('.json'));
        }

        return $versions;
    }

    private function prefix(string $library): string
    {
        return str_replace('/', '-', $library) . '-';
    }
}
