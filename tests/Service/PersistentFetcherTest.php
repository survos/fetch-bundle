<?php

declare(strict_types=1);

namespace Survos\FetchBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\FetchBundle\Retry\ExponentialBackoffRetry;
use Survos\FetchBundle\Service\PersistentFetcher;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PersistentFetcherTest extends TestCase
{
    /** Small attempts/delays so retry tests stay fast without changing the retry logic under test. */
    private function retry(int $maxAttempts = 3): ExponentialBackoffRetry
    {
        return new ExponentialBackoffRetry(maxAttempts: $maxAttempts, baseDelayMs: 1, maxDelayMs: 2);
    }

    public function testRepeatedFetchIsServedFromCacheNotTheNetwork(): void
    {
        $calls = 0;
        $mock = new MockHttpClient(function () use (&$calls) {
            $calls++;

            return new MockResponse('hello ' . $calls, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/plain']]);
        });

        $fetcher = new PersistentFetcher($mock, new ArrayAdapter(), $this->retry());

        $first = $fetcher->fetch('https://example.com/page');
        $second = $fetcher->fetch('https://example.com/page');

        self::assertSame(1, $calls, 'second identical fetch should be served from cache, not hit the network');
        self::assertSame($first->contents, $second->contents);
        self::assertSame('hello 1', $first->contents);
        self::assertTrue($first->isOkay());
    }

    public function testDifferentMethodsForTheSameUrlAreCachedSeparately(): void
    {
        $calls = 0;
        $mock = new MockHttpClient(function () use (&$calls) {
            $calls++;

            return new MockResponse('body', ['http_code' => 200]);
        });

        $fetcher = new PersistentFetcher($mock, new ArrayAdapter(), $this->retry());

        $fetcher->fetch('https://example.com/page', ['method' => 'GET']);
        $fetcher->fetch('https://example.com/page', ['method' => 'HEAD']);

        self::assertSame(2, $calls, 'GET and HEAD for the same URL must not collide in the cache key');
    }

    public function testForceFetchBypassesTheCache(): void
    {
        $calls = 0;
        $mock = new MockHttpClient(function () use (&$calls) {
            $calls++;

            return new MockResponse('body ' . $calls, ['http_code' => 200]);
        });

        $fetcher = new PersistentFetcher($mock, new ArrayAdapter(), $this->retry());

        $first = $fetcher->fetch('https://example.com/page');
        $second = $fetcher->fetch('https://example.com/page', ['force_fetch' => true]);

        self::assertSame(2, $calls);
        self::assertNotSame($first->contents, $second->contents);
    }

    public function testForgetInvalidatesTheCache(): void
    {
        $calls = 0;
        $mock = new MockHttpClient(function () use (&$calls) {
            $calls++;

            return new MockResponse('body', ['http_code' => 200]);
        });

        $fetcher = new PersistentFetcher($mock, new ArrayAdapter(), $this->retry());

        $fetcher->fetch('https://example.com/page');
        self::assertTrue($fetcher->isCached('https://example.com/page'));

        $fetcher->forget('https://example.com/page');
        self::assertFalse($fetcher->isCached('https://example.com/page'));

        $fetcher->fetch('https://example.com/page');
        self::assertSame(2, $calls);
    }

    public function testIsCachedReflectsWhetherFetchHasHappened(): void
    {
        $mock = new MockHttpClient(fn () => new MockResponse('body', ['http_code' => 200]));
        $fetcher = new PersistentFetcher($mock, new ArrayAdapter(), $this->retry());

        self::assertFalse($fetcher->isCached('https://example.com/page'));
        $fetcher->fetch('https://example.com/page');
        self::assertTrue($fetcher->isCached('https://example.com/page'));
    }

    public function testRetriesOn429ThenSucceeds(): void
    {
        $responses = [
            new MockResponse('', ['http_code' => 429]),
            new MockResponse('finally', ['http_code' => 200]),
        ];
        // NOT `fn ()`: arrow functions capture by value, so array_shift() would mutate a
        // copy and every call would re-shift the same first (429) element forever.
        $mock = new MockHttpClient(function () use (&$responses) {
            return array_shift($responses);
        });

        $fetcher = new PersistentFetcher($mock, new ArrayAdapter(), $this->retry());
        $result = $fetcher->fetch('https://example.com/rate-limited');

        self::assertSame(200, $result->statusCode);
        self::assertSame('finally', $result->contents);
    }

    public function testGivesUpAfterExhaustingRetriesOnPersistent5xx(): void
    {
        $calls = 0;
        $mock = new MockHttpClient(function () use (&$calls) {
            $calls++;

            return new MockResponse('server error', ['http_code' => 503]);
        });

        $fetcher = new PersistentFetcher($mock, new ArrayAdapter(), $this->retry(maxAttempts: 3));
        $result = $fetcher->fetch('https://example.com/always-down');

        self::assertSame(503, $result->statusCode);
        self::assertSame(3, $calls, 'should attempt exactly maxAttempts times, then return the last failure');
    }

    public function testNonRetryableStatusReturnsImmediatelyWithoutRetrying(): void
    {
        $calls = 0;
        $mock = new MockHttpClient(function () use (&$calls) {
            $calls++;

            return new MockResponse('not found', ['http_code' => 404]);
        });

        $fetcher = new PersistentFetcher($mock, new ArrayAdapter(), $this->retry());
        $result = $fetcher->fetch('https://example.com/missing');

        self::assertSame(404, $result->statusCode);
        self::assertSame(1, $calls, '404 is not in the retryable status list');
        self::assertFalse($result->isOkay());
    }

    public function testFailedFetchIsStillCachedByDefault(): void
    {
        // Matches the pre-existing ScrapeService contract this replaced: a 404 gets cached too,
        // so a broken/missing URL isn't refetched on every call.
        $calls = 0;
        $mock = new MockHttpClient(function () use (&$calls) {
            $calls++;

            return new MockResponse('not found', ['http_code' => 404]);
        });

        $fetcher = new PersistentFetcher($mock, new ArrayAdapter(), $this->retry());
        $fetcher->fetch('https://example.com/missing');
        $fetcher->fetch('https://example.com/missing');

        self::assertSame(1, $calls);
    }
}
