<?php
declare(strict_types=1);

namespace Survos\FetchBundle\Contract\DTO;

use Survos\FetchBundle\Contract\RetryStrategyInterface;

final class FetchOptions
{
    public function __construct(
        public readonly int $concurrency = 8,
        public readonly ?float $timeout = 30.0,
        public readonly array $defaultHeaders = [],
        public readonly ?RetryStrategyInterface $retry = null,
    ) {}
}
