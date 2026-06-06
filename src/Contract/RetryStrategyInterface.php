<?php
declare(strict_types=1);

namespace Survos\FetchBundle\Contract;

interface RetryStrategyInterface
{
    public function shouldRetry(int $attempt, ?int $status = null, ?\Throwable $e = null): bool;
    public function getDelayMs(int $attempt, ?int $status = null, ?\Throwable $e = null): int;
}
