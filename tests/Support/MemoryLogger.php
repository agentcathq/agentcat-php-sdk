<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use Psr\Log\AbstractLogger;

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    /** @param mixed[] $context */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => is_string($level) ? $level : get_debug_type($level), 'message' => (string) $message];
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(static fn(array $record): string => $record['message'], $this->records);
    }
}
