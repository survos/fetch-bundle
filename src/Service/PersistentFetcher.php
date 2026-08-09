<?php

declare(strict_types=1);

namespace Survos\FetchBundle\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Survos\FetchBundle\Contract\DTO\CachedFetchResult;
use Survos\FetchBundle\Contract\PersistentFetcherInterface;
use Survos\FetchBundle\Contract\RetryStrategyInterface;
use Survos\FetchBundle\Http\WipProxy;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cached HTTP fetch where the CALLER decides freshness, not the origin server.
 *
 * Unlike CachingHttpClientFactory (RFC 9111 -- obeys the response's own Cache-Control/Expires),
 * this stores whatever the caller asks for, for as long as the caller asks for it. That's the
 * right fit for sites that send no useful caching headers, which is most of them.
 *
 * Backed by whatever PSR CacheInterface pool is injected -- typically one registered via
 * SqliteCachePoolFactory::register(), so results survive process restarts and cache:clear without
 * needing Redis or the app's own database.
 */
final class PersistentFetcher implements PersistentFetcherInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly RetryStrategyInterface $retryStrategy,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function fetch(string $url, array $options = []): CachedFetchResult
    {
        $method = strtoupper($options['method'] ?? 'GET');
        $ttl = $options['ttl'] ?? 0; // seconds; 0 = cache forever until force_fetch/forget()
        $forceFetch = (bool) ($options['force_fetch'] ?? false);
        $headers = $options['headers'] ?? [];
        $timeout = $options['timeout'] ?? null; // seconds; null = client's own default
        $maxAttempts = $options['max_attempts'] ?? null; // null = defer to the injected RetryStrategyInterface

        $key = $this->cacheKey($method, $url);

        if ($forceFetch) {
            $this->cache->delete($key);
        }

        return $this->cache->get($key, function (ItemInterface $item) use ($method, $url, $headers, $timeout, $ttl, $maxAttempts) {
            if ($ttl > 0) {
                $item->expiresAfter($ttl);
            }

            return $this->actuallyFetch($method, $url, $headers, $timeout, $maxAttempts);
        });
    }

    public function isCached(string $url, string $method = 'GET'): bool
    {
        return $this->cache->hasItem($this->cacheKey($method, $url));
    }

    public function forget(string $url, string $method = 'GET'): void
    {
        $this->cache->delete($this->cacheKey($method, $url));
    }

    private function cacheKey(string $method, string $url): string
    {
        return sprintf('%s_%s', $method, hash('xxh3', $url));
    }

    /**
     * Requests a URL, retrying transport errors and 429/5xx responses via the injected
     * RetryStrategyInterface (exponential backoff with jitter) -- same retry contract already
     * used by SymfonyConcurrentFetcher.
     */
    private function actuallyFetch(string $method, string $url, array $headers, ?float $timeout = null, ?int $maxAttempts = null): CachedFetchResult
    {
        $requestOptions = [
            'max_redirects' => 4,
            'headers' => $headers,
            ...WipProxy::optionsFor($url),
        ];
        if ($timeout !== null) {
            // 'timeout' alone is an idle timeout -- a connection that stalls mid-handshake
            // (dead host, redirect to a dead host) can outlast it. 'max_duration' is a genuine
            // wall-clock ceiling on the whole request/redirect-chain, so a caller-supplied
            // timeout actually bounds worst-case time, not just idle gaps.
            $requestOptions['timeout'] = $timeout;
            $requestOptions['max_duration'] = $timeout;
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $response = $this->httpClient->request($method, $url, $requestOptions);
                $statusCode = $response->getStatusCode();
            } catch (TimeoutException|TransportException $e) {
                if (($maxAttempts === null || $attempt < $maxAttempts) && $this->retryStrategy->shouldRetry($attempt, null, $e)) {
                    usleep($this->retryStrategy->getDelayMs($attempt, null, $e) * 1000);
                    continue;
                }

                return new CachedFetchResult(
                    url: $url,
                    finalUrl: null,
                    method: $method,
                    statusCode: -1,
                    contentType: null,
                    contentLength: null,
                    contents: null,
                    errorMessage: $e->getMessage(),
                    fetchedAt: time(),
                );
            }

            if (\in_array($statusCode, [429, 500, 502, 503, 504], true)
                && ($maxAttempts === null || $attempt < $maxAttempts)
                && $this->retryStrategy->shouldRetry($attempt, $statusCode, null)) {
                $this->logger->info(sprintf('Retrying %s after status %d (attempt %d)', $url, $statusCode, $attempt));
                usleep($this->retryStrategy->getDelayMs($attempt, $statusCode, null) * 1000);
                continue;
            }

            $responseHeaders = [];
            $contentType = null;
            $contentLength = null;

            try {
                $responseHeaders = $response->getHeaders(false);
                $contentType = $responseHeaders['content-type'][0] ?? null;
                $contentLength = isset($responseHeaders['content-length'][0]) ? (int) $responseHeaders['content-length'][0] : null;
            } catch (\Throwable) {
                // headers unavailable -- fall through with what we have
            }

            return new CachedFetchResult(
                url: $url,
                finalUrl: $response->getInfo('url') ?? $url,
                method: $method,
                statusCode: $statusCode,
                contentType: $contentType,
                contentLength: $contentLength,
                contents: $response->getContent(false),
                errorMessage: null,
                fetchedAt: time(),
            );
        }
    }
}
