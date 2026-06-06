<?php
declare(strict_types=1);

namespace Survos\FetchBundle\Contract\Retry;

interface RetryStrategyInterface
{
    /** Decide if retry should occur (attempt is 1-based). */
    public function shouldRetry(int $attempt, ?int $statusCode, ?\Throwable $error): bool;

    /** Milliseconds to wait before next attempt (supports jitter, Retry-After, etc.). */
    public function backoffDelayMs(int $attempt, ?int $statusCode, ?\Throwable $error): int;
}
