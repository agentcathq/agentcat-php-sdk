<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E\Scenarios;

use AgentCat\Internal\Json\Json;
use PHPUnit\Framework\Assert;

/**
 * What the scenario tools received, one JSON line per call: the strip proof. The server writes it while it serves the
 * call, so the line is there when the client has the answer; the test reads it back. A sink without a file records
 * nothing (a request that names no sink).
 */
final readonly class Sink
{
    /** @param string|null $file the JSON-lines file, or null to record nothing */
    public function __construct(private ?string $file) {}

    /** @param array<array-key, mixed> $arguments */
    public function record(string $tool, array $arguments): void
    {
        if ($this->file !== null) {
            file_put_contents($this->file, json_encode(['tool' => $tool, 'args' => (object) $arguments], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * The arguments that `$tool` received, as JSON, one item per call in call order.
     *
     * @return list<string>
     */
    public function seen(string $tool): array
    {
        $contents = $this->file !== null && is_file($this->file) ? file_get_contents($this->file) : '';
        Assert::assertIsString($contents);
        $seen = [];
        foreach (array_filter(explode("\n", $contents), static fn(string $line): bool => $line !== '') as $line) {
            $entry = Json::decode($line);
            Assert::assertInstanceOf(\stdClass::class, $entry, $line);
            if (($entry->tool ?? null) === $tool) {
                $seen[] = json_encode($entry->args ?? null, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
        }

        return $seen;
    }
}
