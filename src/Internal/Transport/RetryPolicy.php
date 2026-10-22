<?php

declare(strict_types=1);

namespace AgentCat\Internal\Transport;

/**
 * How often and after how long a failed delivery is tried again. Every error is retried (D15).
 *
 * @internal
 */
final readonly class RetryPolicy
{
    /** TypeScript `maxRetries`: 4 attempts in total. */
    public const int MAX_RETRIES = 3;

    /**
     * @param list<float> $delaysSeconds the wait after attempt 1, 2, …; TS `Math.pow(2, retries) * 1000` ms
     */
    public function __construct(
        public array $delaysSeconds = [1.0, 2.0, 4.0],
    ) {}

    public function maxAttempts(): int
    {
        return count($this->delaysSeconds) + 1;
    }

    /** Seconds to wait after the failed 1-based `$attempt`; null = give up. */
    public function delayAfterAttempt(int $attempt): ?float
    {
        return $this->delaysSeconds[$attempt - 1] ?? null;
    }
}
