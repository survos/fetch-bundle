# Fetch Bundle

[![Latest Stable Version](https://img.shields.io/packagist/v/survos/fetch-bundle)](https://packagist.org/packages/survos/fetch-bundle)
[![License](https://img.shields.io/badge/license-MIT-informational)](LICENSE)

Reusable HTTP fetch primitives for Symfony applications: caching, retry/backoff, bounded concurrency, and resumable downloads. General-purpose -- most consumers just want a cached, retrying HTTP client, not a dataset pipeline.

This bundle started as `multi-fetch-bundle`, an experiment around parallel API fetching. The useful core turned out to be broader than concurrency; most consumers need some subset of:

- HTTP caching, either RFC 9111 (`CachingHttpClientFactory`, obeys the origin's own Cache-Control/Expires) or app-controlled TTL (`PersistentFetcher`, backed by a disposable SQLite pool -- for sources that send no useful caching headers, which is most of them);
- retry and backoff for transient failures (`ExponentialBackoffRetry`);
- bounded-concurrency HTTP requests (`SymfonyConcurrentFetcher`);
- resumable downloads for large files (`ChunkDownloader`);
- optionally, paginated fetch-to-JSONL for dataset harvesting (`Paginator`/`PaginatedFetchMessageHandler`, page planning for offset, page-number, and cursor APIs) -- only when `survos/jsonl-bundle` is installed, see Dependencies below.

The package is `survos/fetch-bundle`. "Multi" (concurrent fetching) is just one execution mode; the resumable `ChunkDownloader` and cache helpers stand on their own.

## Current Services

`SymfonyConcurrentFetcher` implements bounded concurrent HTTP fetching using Symfony HttpClient streaming. It accepts keyed request metadata and yields keyed response arrays as requests complete.

`ExponentialBackoffRetry` provides a simple retry policy for transport errors, HTTP 429, and 5xx responses.

`ChunkDownloader` downloads large files to `*.part`, supports HTTP Range resume when the source honors it, retries transient failures, and reports byte progress.

`PersistentFetcher` (+ `SqliteCachePoolFactory`) caches a fetch by URL+method for as long as the caller says, ignoring the origin's own caching headers -- the right fit for scraping sites that send `no-store` or nothing at all. Every fetch also gets `WipProxy::optionsFor($url)` merged in automatically, so any `*.wip` URL is transparently routed through the local Symfony CLI proxy (127.0.0.1:7080) without callers needing their own `str_contains($url, '.wip')` check.

`WipProxy` centralizes the "route `.wip` hosts through the local dev proxy" convention that used to be copy-pasted ad hoc across services (`optionsFor()` for a one-off `HttpClientInterface` call, `WipProxyHttpClient` to decorate a scoped client's base_uri at construction).

`multi:fetch` is an experimental CLI for Solr/JSON/JSON-LD style sources. It writes rows with `Survos\JsonlBundle\IO\JsonlWriter`. Only registered when `survos/jsonl-bundle` is installed.

## Dependencies

`survos/jsonl-bundle` (plus `symfony/messenger` and `symfony/event-dispatcher`) are optional -- `suggest`, not `require`. They're only used by the `Paginate/*` slice (paginated fetch-to-JSONL for dataset harvesting). `SurvosFetchBundle::loadExtension()` registers that slice conditionally behind `class_exists(JsonlWriter::class)`, so an app that just wants `PersistentFetcher`/`SymfonyConcurrentFetcher`/`ChunkDownloader` isn't forced to pull in JSONL tooling or a message bus it doesn't otherwise use.

## Example

```php
use Survos\FetchBundle\Contract\ConcurrentFetcherInterface;
use Survos\FetchBundle\Contract\DTO\FetchOptions;

final class DatasetFetcher
{
    public function __construct(
        private readonly ConcurrentFetcherInterface $fetcher,
    ) {}

    public function fetch(array $urls): iterable
    {
        $requests = [];
        foreach ($urls as $i => $url) {
            $requests[$i] = ['url' => $url];
        }

        yield from $this->fetcher->fetchMany($requests, new FetchOptions(
            concurrency: 8,
            timeout: 60.0,
            defaultHeaders: ['Accept' => 'application/json'],
        ));
    }
}
```

## Harvest References

The next design pass should extract repetition from Harvest dataset commands such as:

- `dataset:fetch:belvedere`: page-number API, XML parse, stop on empty page;
- `dataset:fetch:victoria`: page-number API, JSON parse, sidecar/count based resume;
- `dataset:fetch:aust`: offset/limit API, multiple raw output cores;
- `dataset:fetch:walters`: large archive download and local CSV-to-JSONL conversion.

Source-specific parsing and row normalization should stay in applications. Pagination, retry, resume, cache behavior, and JSONL output targets belong here.

## TUI Progress

Symfony 8.1's TUI component is a good fit for visualizing concurrent fetches, but it should be an optional presentation layer over the fetch engine.

The core fetch service should emit structured progress events such as `planned`, `started`, `bytes`, `pageComplete`, `retry`, `failed`, and `merged`. A TUI renderer can show one row per active page/download, plus aggregate totals and a log pane. Non-interactive runs should use the same events for normal console progress output.

For precomputed page ranges, concurrent downloads can be displayed naturally: page number, URL/key, status, retries, bytes, rows, and elapsed time. For cursor or `nextPage` APIs, concurrency is usually limited because the next URL is discovered only after reading the current response; the TUI still helps by showing cursor progress, row counts, retries, and merge state.

A future TUI implementation should follow the `tui-monitor` pattern: keep the engine independent of TUI classes, then put dashboard/widgets in a separate namespace that is only registered when `Symfony\Component\Tui\Tui` exists.

## Generic Pagination Flow

The target generic flow is:

1. Build a fetch plan from endpoint configuration, auth headers/query params, and a pagination strategy.
2. Fetch pages to a temporary directory as page-local JSON/JSONL files.
3. Extract rows from each page using a source-specific selector/extractor.
4. Merge page files in stable order into the final JSONL output with `JsonlWriter`.
5. Write sidecar state so interrupted runs can resume or skip completed pages.

For page-number and offset/limit APIs, the plan can often be known up front and fetched concurrently. For `nextPage`/cursor APIs, planning and fetching are interleaved unless the API also exposes all cursors or a total count.

## MVP Scope

The first useful extraction should be sequential and resumable, not concurrent.

A practical v1 should cover the common Harvest loop:

- read existing JSONL sidecar/count state;
- resume from the correct page or offset;
- fetch one page at a time with retry/backoff and optional delay;
- extract rows from JSON;
- append rows with `JsonlWriter`;
- stop on empty page, missing `nextPage`, or explicit limit.

Victoria is the best first consumer. Belvedere is a good second consumer. Multi-output fetchers such as Aust and archive converters such as Walters should wait until the small sequential API is stable.
