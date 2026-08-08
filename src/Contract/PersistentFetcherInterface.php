<?php

declare(strict_types=1);

namespace Survos\FetchBundle\Contract;

use Survos\FetchBundle\Contract\DTO\CachedFetchResult;

interface PersistentFetcherInterface
{
    /**
     * @param array{method?: string, ttl?: int, force_fetch?: bool, headers?: array<string,string>, timeout?: float} $options
     *     ttl in seconds; 0 (default) means "cache forever, until force_fetch or forget()" --
     *     matching an aggressive scraping cache, not the origin's own Cache-Control.
     *     timeout in seconds; omit to use the injected HttpClient's own default.
     */
    public function fetch(string $url, array $options = []): CachedFetchResult;

    public function isCached(string $url, string $method = 'GET'): bool;

    public function forget(string $url, string $method = 'GET'): void;
}
