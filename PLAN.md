# PLAN.md - survos/multi-fetch-bundle

## Status

This bundle is incomplete but contains useful pieces. Treat the current code as a prototype for a broader `fetch-bundle`.

The copied `SurvosJsonlBundle` class has been removed. JSONL output should use the real `survos/jsonl-bundle` package.

## Direction

Rename the concept from "multi fetch" to "fetch". Parallel fetching remains an option on the execution engine, not the primary abstraction.

The package should provide reusable primitives for dataset harvesting:

- pagination strategies for page-number, offset/limit, cursor, and "until empty" APIs;
- bounded concurrent fetching with retry/backoff;
- sequential polite fetching with delay for APIs that should not be hit in parallel;
- resumable large-file downloads;
- JSONL ingest targets backed by `survos/jsonl-bundle`;
- progress callbacks/listeners for CLI commands;
- HTTP cache decorators/configuration helpers copied from Harvest once generalized.

## Code Review Notes

- `src/Contract/DTO/*` and `src/Contract/*` are the active contracts used by `SymfonyConcurrentFetcher`.
- `src/Contract/Fetch/*` and `src/Contract/Retry/*` are parallel draft contracts and should be reconciled or deleted before consumers rely on them.
- `OrchestratorInterface` references the draft `Contract\Fetch\FetchOptions`, while the implemented fetcher uses `Contract\DTO\FetchOptions`. Normalize this.
- `SurvosMultiFetchBundle` registers aliases twice and then makes core services public. Clean this up into the modern bundle style used by `claims-bundle`/`zebra-bundle`.
- `FetchDownloadCommand` is useful as a demo, but it should not drive the architecture. A future command family should probably live on a service with method-level `#[AsCommand]`, matching current Survos conventions.
- `ChunkDownloader` has valuable behavior, but its constructor default `new NullLogger()` expression should be cleaned up and filesystem writes should use Symfony Filesystem where appropriate.
- The Harvest `ForceFreshHttpClient` decorator likely belongs here behind configuration for allowed host suffixes and TTL.

## Near-Term Tasks

1. Decide package/bundle naming: keep Composer package as `survos/multi-fetch-bundle` for BC or create `survos/fetch-bundle`.
2. Normalize contracts into one namespace and one `FetchOptions` DTO.
3. Add pagination models:
   - `PageNumberPagination`
   - `OffsetLimitPagination`
   - `CursorPagination`
   - `UntilEmptyPagination`
4. Add a high-level `FetchService`/`FetchOrchestrator` that accepts a paginator, extractor, fetch options, and ingest target.
5. Add a `JsonlIngestTarget` using `survos/jsonl-bundle`.
6. Move/generalize Harvest's `ForceFreshHttpClient` decorator into this bundle.
7. Backfill focused tests for:
   - URL planning;
   - concurrency cap;
   - retry decisions;
   - JSONL ingest target writes;
   - resume math.

## Harvest Extraction Targets

Use these as concrete examples while designing:

- `App\Dataset\Belvedere`: page-number XML API, delay, stop on empty page.
- `App\Dataset\Victoria`: page-number JSON API, resume from existing JSONL row count.
- `App\Dataset\Aust`: offset/limit JSON API and multiple output cores.
- `App\Dataset\Larco`: numeric ID HTML pages, sparse/missing pages, cached HTTP, two-stage fetch-then-parse.
- `App\Dataset\Walters`: archive download, force refresh option, CSV extraction.

The goal is that these Harvest commands keep only source-specific URL configuration, parsing, and normalization logic.

## TUI Direction

Use Symfony 8.1 TUI for interactive monitoring, but do not make TUI part of the fetch core.

The right split is:

- core fetch/pagination/orchestration services emit progress events and can run headless;
- console renderer consumes those events for CI and non-interactive terminals;
- TUI renderer consumes the same events when `symfony/tui` is available;
- TUI classes are registered conditionally with `class_exists(\Symfony\Component\Tui\Tui::class)` or moved to an optional companion package if dependency constraints become awkward.

`tui-monitor` is the model: a tick-driven dashboard, a status sidebar/table, and a scrollable log pane. For this bundle, the dashboard should show active page/download slots rather than processes.

Suggested TUI widgets:

- active requests table: key/page, status, attempt, HTTP status, rows, bytes, elapsed;
- completed/failed counters;
- merge progress;
- selected request log/detail pane;
- keybindings for quit, pause scheduling, retry failed, and toggle log follow.

Do not hard-require `symfony/tui` until the bundle intentionally drops Symfony 7.x support. If this bundle becomes Symfony 8.1-only, requiring `symfony/tui:^8.1` is fine.

## Replacement Strategy For Harvest

Do not replace Harvest fetch commands with the current `multi:fetch` command directly. The current command is Solr-oriented and useful as a demo, but Harvest needs source-specific parsing and several pagination modes.

Replace these pieces first:

1. `Belvedere`: generic page-number/`until empty` loop; keep XML `record` extraction in Harvest or an extractor callback.
2. `Victoria`: generic page-number loop with resume from JSONL sidecar/count; keep item ID cleanup in Harvest.
3. `Aust`: generic offset/limit loop; keep row expansion and multi-core routing in Harvest until `IngestTarget` supports multiple outputs cleanly.
4. `Walters` and `Cleveland`: use `ChunkDownloader` for large archive/blob downloads before local conversion.
5. Harvest `ForceFreshHttpClient`: move here as a configurable cache helper.

The future bundle command should be generic enough for simple cases:

```bash
bin/console fetch:json ENDPOINT OUT.jsonl \
  --auth-header='Authorization: Bearer ...' \
  --pagination=page-number \
  --page-param=page \
  --first-page=1 \
  --items-path='data.items' \
  --next-path='links.next' \
  --concurrency=8
```

But application dataset commands should usually call a service API instead of shelling out to the generic command. That keeps dataset metadata, parsing, and normalization in one class while removing the repeated fetch loop.

## MVP Scope Cut

Hold concurrency until the sequential API is genuinely useful.

The first reusable feature should be a boring, resumable, sequential page fetcher. It should target Harvest-style commands that currently repeat this pattern:

1. Determine the output JSONL path.
2. Read existing jsonl-bundle sidecar/count state unless `--force` is set.
3. Compute the starting page or offset.
4. Fetch one page at a time with retry/backoff and optional delay.
5. Extract rows from the page payload.
6. Append rows through `JsonlWriter`.
7. Finish the writer so sidecar state is correct.
8. Stop on empty page, `nextPage === null`, or explicit limit.

This is useful before any TUI or concurrency work. Concurrency can be layered later by swapping the runner behind the same paginator/extractor/target contracts.

Best first Harvest targets:

1. `Victoria`, because it already has explicit `FIRST_PAGE`, `PER_PAGE`, jsonl-bundle row counting, append mode, and `--force` behavior.
2. `Larco`, because it is actually a strong fit for the bundle once split into two stages:
   - fetch numeric detail IDs into raw HTML files;
   - parse raw HTML files into canonical `obj.jsonl`.
3. `Belvedere`, for `until empty` page-number APIs.

`Aust` should wait until multi-output ingest is clearer.

Suggested first service shape:

```php
$result = $fetchPager->run(new SequentialJsonFetchPlan(
    endpoint: 'https://collections.museumsvictoria.com.au/api/items',
    output: $rawFile,
    pageParam: 'page',
    firstPage: 0,
    perPageParam: 'perpage',
    perPage: 100,
    itemsPath: '',
    stopWhenEmpty: true,
    force: $force,
    delaySeconds: $delay,
    transformRow: static fn(array $item): ?array => $item,
));
```

The actual API should be cleaner than this sketch, but the important point is scope: sequential, resumable, JSONL-backed, easy to call from an existing dataset command.

## Larco Handoff Notes

Harvest now has a useful Larco prototype, but the architecture should change before extracting it fully:

- Do not fetch and parse in the same loop.
- Stage 1 should download detail HTML pages to disk, probably under a source-specific raw HTML directory such as `05_raw/html/{id}.html`.
- Stage 2 should parse those saved HTML files into canonical `05_raw/obj.jsonl` rows.
- The parser belongs in an app-level `LarcoParser` service and can be tested with local HTML fixtures.
- The bundle should provide the generic numeric-ID fetcher: `firstId`, `lastId`, `limit`, `force`, `delay`, `requestTimeout`, retry/backoff, skip-on-timeout, and progress.
- Sparse IDs are normal. A 404 or parser-empty page should be counted and skipped, not treated as a failure.
- Resume should come from jsonl-bundle sidecar/state where possible. For raw HTML downloads, the equivalent state is existing files plus a sidecar/manifest. Do not add app-specific `saveState()` files.
- jsonl-bundle sidecars probably need an app-specific `extra`/context bag so fetchers can record harmless hints such as last attempted page/ID, last successful page/ID, skipped count, timeout count, and source URL template.
- Harvest's cached HTTP decorator exposed a problem: a request can appear to hang in the Symfony cached client path. Fetch plans need a hard per-request `max_duration` and should skip timed-out pages.
- Larco should not preserve Larco's Spanish presentation keys in the new raw JSONL. Since the parser controls the raw rows, write canonical keys directly: `ItemField::TITLE`, `MuseumVocab::CULTURE`, `MuseumVocab::MEDIUM`, `MuseumVocab::SUBJECT`, `MuseumVocab::DIMENSIONS`, etc.
- Dimensions should be structured before import when possible, matching the dimensions normalizer shape: dimensions rows with `units`, `height`, `width`, `length`, etc.; weight as `amount` plus `units`.

Likely reusable service shape for Larco stage 1:

```php
$result = $numericIdFetcher->download(new NumericIdDownloadPlan(
    urlTemplate: 'https://coleccion.museolarco.org/detail/{id}',
    outputTemplate: $rawHtmlDir . '/{id}.html',
    firstId: 1,
    lastId: 47000,
    force: $force,
    requestTimeout: 10.0,
    delaySeconds: $delay,
    skipStatusCodes: [404],
));
```

Likely stage 2 stays mostly in Harvest:

```php
foreach ($htmlFiles as $id => $htmlFile) {
    $row = $larcoParser->parseDetailHtml(file_get_contents($htmlFile), $id);
    if ($row !== null) {
        $writer->write($row, (string) $row[ItemField::ID]);
    }
}
```
