# Fetch Bundle

Reusable HTTP fetch utilities for Symfony applications that harvest remote data into JSONL.

This bundle started as `multi-fetch-bundle`, an experiment around parallel API fetching. The useful core is broader than concurrency: most dataset fetchers need the same small set of primitives:

- bounded-concurrency HTTP requests;
- retry and backoff for transient failures;
- page planning for offset, page-number, and cursor APIs;
- resumable downloads for large files;
- JSONL output through `survos/jsonl-bundle`;
- optional HTTP cache helpers for source APIs that do not send useful cache headers.

The current package name is still `survos/multi-fetch-bundle`, but the intended direction is a general `fetch-bundle`, where "multi" is just one execution mode.

## Current Services

`SymfonyConcurrentFetcher` implements bounded concurrent HTTP fetching using Symfony HttpClient streaming. It accepts keyed request metadata and yields keyed response arrays as requests complete.

`ExponentialBackoffRetry` provides a simple retry policy for transport errors, HTTP 429, and 5xx responses.

`ChunkDownloader` downloads large files to `*.part`, supports HTTP Range resume when the source honors it, retries transient failures, and reports byte progress.

`multi:fetch` is an experimental CLI for Solr/JSON/JSON-LD style sources. It writes rows with `Survos\JsonlBundle\IO\JsonlWriter`.

## Dependencies

`survos/jsonl-bundle` is a hard dependency because JSONL is the canonical output format for Survos dataset harvesting. This bundle should depend on jsonl-bundle, not copy classes from it.

## Example

```php
use Survos\MultiFetchBundle\Contract\ConcurrentFetcherInterface;
use Survos\MultiFetchBundle\Contract\DTO\FetchOptions;

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
