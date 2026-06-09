<?php
declare(strict_types=1);

namespace Survos\FetchBundle\Paginate\Event;

use Survos\FetchBundle\Paginate\Dto\PageRequest;
use Survos\FetchBundle\Paginate\Message\PaginatedFetchMessage;

/**
 * Dispatched after a page has been fetched, so a provider-specific listener can:
 *   - parse the response into $rows (the records to append to JSONL), and
 *   - describe the next page via $next (null = last page, stop).
 *
 * Listeners guard on $this->sourceCode() so multiple providers can coexist.
 * The paginator owns transport/retry/JSONL/sidecar; the listener owns parsing
 * and next-page logic (URL + application context such as page size / page number).
 */
final class PageFetchedEvent
{
    /**
     * Rows to append to the primary JSONL file (the message's jsonlPath).
     *
     * A row MAY carry a "_token" key: when present the paginator pops it and uses
     * it as the JsonlWriter dedup token (skips rows whose token was already written
     * to that file), then writes the row without "_token". Use it for entities that
     * repeat across pages (e.g. a shared collection/creator).
     *
     * @var list<array<string,mixed>>
     */
    public array $rows = [];

    /**
     * Additional sibling files written this page: absolute path => rows (same
     * "_token" dedup rule as $rows). For providers that fan one source record out
     * into several JSONL files. These are satellites of the primary file — only the
     * primary carries the resume checkpoint, so the producer is responsible for
     * truncating them on a cold start.
     *
     * @var array<string, list<array<string,mixed>>>
     */
    public array $files = [];

    /** Next page to fetch, or null when there are no more pages. */
    public ?PageRequest $next = null;

    /**
     * @param array<string,mixed>|null    $data    decoded JSON body (null if not decodable)
     * @param array<string,list<string>>  $headers response headers
     */
    public function __construct(
        public readonly PaginatedFetchMessage $message,
        public readonly string $body,
        public readonly ?array $data,
        public readonly int $statusCode,
        public readonly array $headers = [],
    ) {
    }

    public function sourceCode(): string
    {
        return $this->message->sourceCode;
    }

    /** @return array<string,mixed> application context carried into this page */
    public function meta(): array
    {
        return $this->message->meta;
    }
}
