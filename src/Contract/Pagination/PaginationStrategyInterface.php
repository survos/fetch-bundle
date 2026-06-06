<?php
declare(strict_types=1);

namespace Survos\FetchBundle\Contract\Pagination;

/**
 * Produces a sequence of units (e.g., offset blocks, page numbers, or cursor requests)
 * from a probe result and a resume point.
 */
interface PaginationStrategyInterface
{
    /**
     * @return \Traversable<array> Each element is a unit descriptor (free-form array).
     */
    public function plan(ProbeResult $probe, ResumePoint $resume): \Traversable;
}
