<?php
declare(strict_types=1);

namespace Survos\FetchBundle\Contract\Pagination;

/** Result of a probe request (e.g., total hits or first cursor token). */
final class ProbeResult
{
    public function __construct(
        public readonly int $total,           // -1 if unknown (cursor-only APIs)
        public readonly array $meta = []      // e.g., ['cursor' => 'xyz']
    ) {}
}
