<?php
declare(strict_types=1);

namespace Survos\FetchBundle\Paginate\Message;

/**
 * Fetch one page of a paginated source and (if there is a next page) dispatch the
 * next PaginatedFetchMessage to $transport. No database: state lives in the JSONL
 * file at $jsonlPath and its sidecar (Survos\JsonlBundle\Service\JsonlStateService).
 *
 * Distinct from Survos\ImportBundle\Message\FetchPageMessage, which is entity-backed.
 *
 * Small and serializable by design — provider-specific parsing/next-page logic is
 * supplied by listeners on PageFetchedEvent, keyed by $sourceCode.
 */
final class PaginatedFetchMessage
{
    /** @param array<string,mixed> $meta */
    public function __construct(
        public readonly string $sourceCode,
        public readonly string $jsonlPath,
        public readonly string $url,
        public readonly array $meta = [],
        public readonly string $transport = 'async',
    ) {
    }
}
