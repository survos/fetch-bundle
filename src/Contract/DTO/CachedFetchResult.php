<?php

declare(strict_types=1);

namespace Survos\FetchBundle\Contract\DTO;

/**
 * Result of a PersistentFetcher::fetch() call -- cached verbatim, including non-2xx responses,
 * so a 404 or 403 doesn't get re-fetched on every request either.
 */
final class CachedFetchResult
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $finalUrl,
        public readonly string $method,
        public readonly int $statusCode,
        public readonly ?string $contentType,
        public readonly ?int $contentLength,
        public readonly ?string $contents,
        public readonly ?string $errorMessage,
        public readonly int $fetchedAt,
    ) {
    }

    public function isOkay(): bool
    {
        return $this->statusCode === 200;
    }
}
