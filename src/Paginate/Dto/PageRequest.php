<?php
declare(strict_types=1);

namespace Survos\FetchBundle\Paginate\Dto;

/**
 * A single page to fetch: the URL plus free-form pagination state (offset/page/
 * cursor, running counters, per-page size, limits, optional request headers).
 *
 * Producers (provider listeners) put whatever they need into $meta; the paginator
 * carries it opaquely from one page to the next so the bundle stays generic.
 */
final readonly class PageRequest
{
    /** @param array<string,mixed> $meta */
    public function __construct(
        public string $url,
        public array $meta = [],
    ) {
    }
}
