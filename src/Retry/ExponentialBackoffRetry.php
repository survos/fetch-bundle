<?php
declare(strict_types=1);

namespace Survos\FetchBundle\Retry;

use Survos\FetchBundle\Contract\RetryStrategyInterface;

final class ExponentialBackoffRetry implements RetryStrategyInterface
{
    public function __construct(
        private readonly int $maxAttempts = 5,
        private readonly int $baseDelayMs = 200,
        private readonly int $maxDelayMs  = 10_000
    ) {}

    public function shouldRetry(int $attempt, ?int $status = null, ?\Throwable $e = null): bool
    {
        if ($attempt >= $this->maxAttempts) return false;
        if ($e !== null) return true;
        if ($status === 429) return true;
        if ($status !== null && $status >= 500 && $status < 600) return true;
        return false;
    }

    public function getDelayMs(int $attempt, ?int $status = null, ?\Throwable $e = null): int
    {
        $exp = $this->baseDelayMs * (2 ** max(0, $attempt - 1));
        $cap = min($exp, $this->maxDelayMs);
        return random_int(0, max(0, $cap)); // full jitter
    }
}
