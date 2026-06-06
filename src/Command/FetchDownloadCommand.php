<?php
declare(strict_types=1);

namespace Survos\FetchBundle\Command;

use Survos\FetchBundle\Contract\ConcurrentFetcherInterface;
use Survos\FetchBundle\Contract\DTO\FetchOptions;
use Survos\FetchBundle\Contract\RetryStrategyInterface;
use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'multi:fetch', description: 'Fetch from an API (Solr/JSON) concurrently and write items to JSONL(.gz) with ETA.')]
final class FetchDownloadCommand
{
    public function __construct(
        private readonly ConcurrentFetcherInterface $fetcher,
        private readonly RetryStrategyInterface $retry
    ) {}

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('The base URL to fetch. For Solr, pass the /select endpoint without params or with partial params.')]
        string $url,

        #[Argument('Output file path (.jsonl or .jsonl.gz).')]
        string $output,

        #[Option('Output format for extracting items: raw, jsonld, or solr.')]
        string $format = 'solr',

        #[Option('Rows per page (page size). For Solr, maps to rows=.')]
        int $rows = 1000,

        #[Option('Max total records to fetch (0 = all available from probe).')]
        int $max = 0,

        #[Option('Max number of concurrent HTTP requests.')]
        int $concurrency = 8,

        #[Option('Timeout in seconds per request (null to use HttpClient default).')]
        float $timeout = 30.0
    ): int {
        $format = \strtolower($format);
        if (!\in_array($format, ['raw', 'jsonld', 'solr'], true)) {
            $io->error("Unknown format: {$format}. Use one of: raw, jsonld, solr.");
            return 1;
        }
        if ($rows < 1) {
            $io->error('rows must be >= 1');
            return 1;
        }
        if ($concurrency < 1) {
            $io->error('concurrency must be >= 1');
            return 1;
        }

        $io->title('Multi-fetch');
        $io->writeln("URL:     <info>{$url}</info>");
        $io->writeln("Output:  <info>{$output}</info>");
        $io->writeln("Format:  <comment>{$format}</comment>");
        $io->writeln("Rows:    <comment>{$rows}</comment>");
        $io->writeln("Max:     <comment>{$max}</comment>");
        $io->writeln("Conc:    <comment>{$concurrency}</comment>");
        $io->writeln("Timeout: <comment>" . ($timeout === null ? 'default' : (string)$timeout) . "</comment>");

        $t0 = \microtime(true);

        // -------- Probe phase (for Solr) --------
        $totalAvailable = null;
        if ($format === 'solr') {
            $probeUrl = $this->withParams($url, ['rows' => 0, 'start' => 0]) ;
            // ensure wt=json unless explicitly provided
            if (!\preg_match('~[?&]wt=~i', $probeUrl)) {
                $probeUrl = $this->withParams($probeUrl, ['wt' => 'json']);
            }
            $io->writeln("Probing: <info>{$probeUrl}</info>");

            $opts = new FetchOptions(
                concurrency: 1,
                timeout: $timeout,
                defaultHeaders: ['Accept' => 'application/json'],
                retry: $this->retry
            );

            $probeRes = null;
            foreach ($this->fetcher->fetchMany([0 => ['url' => $probeUrl]], $opts) as $key => $res) {
                $probeRes = $res;
                break;
            }
            if (!$probeRes || ($probeRes['status'] ?? 0) !== 200) {
                $io->error('Probe failed: HTTP ' . (($probeRes['status'] ?? 0) ?: '0'));
                return 2;
            }
            $decoded = null;
            try {
                $decoded = \json_decode($probeRes['body'] ?? '', true, flags: \JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                $io->error('Probe decode failed: ' . $e->getMessage());
                return 2;
            }
            $numFound = $decoded['response']['numFound'] ?? null;
            if (!\is_int($numFound)) {
                $io->error('Probe did not return response.numFound');
                return 2;
            }
            $totalAvailable = $numFound;
            $io->writeln("Probe total numFound: <info>{$numFound}</info>");
        }

        // -------- Planning --------
        $targetTotal = ($max > 0) ? $max : ($totalAvailable ?? 0);

        $requests = [];
        if ($format === 'solr') {
            if ($targetTotal <= 0 && $totalAvailable !== null) {
                $targetTotal = $totalAvailable;
            }
            $pages = (int)\ceil($targetTotal / $rows);
            $io->writeln(sprintf('Planning: <info>%d</info> page(s) @ rows=%d (target=%s)', $pages, $rows, $targetTotal));
            for ($i = 0; $i < $pages; $i++) {
                $start = $i * $rows;
                $pageUrl = $this->withParams($url, ['start' => $start, 'rows' => $rows]);
                if (!\preg_match('~[?&]wt=~i', $pageUrl)) {
                    $pageUrl = $this->withParams($pageUrl, ['wt' => 'json']);
                }
                if ($io->isVerbose()) {
                    $io->writeln(sprintf('Enqueue page #%d start=%d rows=%d', $i, $start, $rows));
                }
                $requests[$i] = ['url' => $pageUrl];
            }
        } else {
            // raw/jsonld: single request
            $requests[0] = ['url' => $url];
            $targetTotal = 0; // unknown
        }

        if (empty($requests)) {
            $io->warning('No requests planned.');
            return 0;
        }

        $io->writeln('Starting fetch…');

        $opts = new FetchOptions(
            concurrency: $concurrency,
            timeout: $timeout,
            defaultHeaders: ['Accept' => 'application/json'],
            retry: $this->retry
        );

        $writer = JsonlWriter::open($output);

        $okDocs = 0;
        $okPages = 0;
        $failPages = 0;
        $pageDocs = []; // key => docs count

        $startedAt = \microtime(true);

        foreach ($this->fetcher->fetchMany($requests, $opts) as $key => $res) {
            $status = $res['status'] ?? 0;
            $body   = $res['body']   ?? '';

            if ($status !== 200) {
                $failPages++;
                if ($io->isVerbose()) {
                    $io->warning(sprintf('Page #%s failed status=%d url=%s', (string)$key, $status, $requests[$key]['url'] ?? ''));
                }
                continue;
            }

            $decoded = null;
            try {
                $decoded = \json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                // leave null
            }

            $docsWrittenThisPage = 0;

            switch ($format) {
                case 'raw':
                    $writer->write([
                        'url'    => $requests[$key]['url'] ?? $url,
                        'status' => $status,
                        '_raw'   => $body,
                    ], $requests[$key]['url'] ?? $url);
                    $docsWrittenThisPage = 1;
                    break;

                case 'jsonld':
                    if (!\is_array($decoded)) {
                        $writer->write(['url' => $requests[$key]['url'] ?? $url, 'status' => $status, '_raw' => $body], $requests[$key]['url'] ?? $url);
                        $docsWrittenThisPage = 1;
                        break;
                    }
                    $items = [];
                    if (isset($decoded['@graph']) && \is_array($decoded['@graph'])) {
                        $items = $decoded['@graph'];
                    } elseif (\array_is_list($decoded)) {
                        $items = $decoded;
                    } else {
                        $items = [$decoded];
                    }
                    foreach ($items as $item) {
                        if (!\is_array($item)) { $item = ['value' => $item]; }
                        $writer->write($item);
                        $docsWrittenThisPage++;
                    }
                    break;

                case 'solr':
                    if (!\is_array($decoded)) {
                        $writer->write(['url' => $requests[$key]['url'] ?? $url, 'status' => $status, '_raw' => $body], $requests[$key]['url'] ?? $url);
                        $docsWrittenThisPage = 1;
                        break;
                    }
                    $docs = $decoded['response']['docs'] ?? ($decoded['docs'] ?? null);
                    if (\is_array($docs)) {
                        foreach ($docs as $doc) {
                            if (!\is_array($doc)) { $doc = ['value' => $doc]; }
                            $writer->write($doc, $doc['id'] ?? null);
                            $docsWrittenThisPage++;
                        }
                    } else {
                        $writer->write($decoded, $requests[$key]['url'] ?? $url);
                        $docsWrittenThisPage = 1;
                    }
                    break;
            }

            $okDocs += $docsWrittenThisPage;
            $okPages++;
            $pageDocs[$key] = $docsWrittenThisPage;

            // ---- Progress / ETA ----
            $elapsed = \microtime(true) - $startedAt;
            $rate = ($elapsed > 0) ? ($okDocs / $elapsed) : 0.0; // docs/sec
            $etaText = '';
            if ($format === 'solr' && $targetTotal > 0 && $rate > 0) {
                $remaining = max(0, $targetTotal - $okDocs);
                $etaSec = (int)\round($remaining / $rate);
                $etaText = ' | ETA ' . $this->fmtDuration($etaSec);
            }
            if ($io->isVerbose()) {
                $io->writeln(sprintf(
                    'Page #%s ok=%d docs(+%d) rate=%s docs/s%s',
                    (string)$key,
                    $okDocs,
                    $docsWrittenThisPage,
                    $rate > 0 ? \number_format($rate, 1) : '0.0',
                    $etaText
                ));
            } else {
                // lightweight single-line update
                $io->write(sprintf("\rProgress: %s pages ok, %s docs%s", $okPages, \number_format($okDocs), $etaText));
            }
        }

        $writer->finish();

        $totalElapsed = \microtime(true) - $t0;
        $io->writeln(''); // newline after \r
        $io->success(sprintf(
            'Done. pages_ok=%d pages_fail=%d docs=%s | time=%s',
            $okPages,
            $failPages,
            \number_format($okDocs),
            $this->fmtDuration((int)\round($totalElapsed))
        ));

        return $failPages > 0 ? 2 : 0;
    }

    private function withParams(string $baseUrl, array $params): string
    {
        // merge params into existing query
        $parts = \parse_url($baseUrl);
        $existing = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $existing);
        }
        $merged = $existing + $params;
        $qs = http_build_query($merged, arg_separator: '&', encoding_type: \PHP_QUERY_RFC3986);

        $scheme   = $parts['scheme']  ?? 'http';
        $host     = $parts['host']    ?? '';
        $port     = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path     = $parts['path']    ?? '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return "{$scheme}://{$host}{$port}{$path}" . ($qs ? "?{$qs}" : '') . $fragment;
    }

    private function fmtDuration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($h > 0) {
            return sprintf('%dh %02dm %02ds', $h, $m, $s);
        }
        if ($m > 0) {
            return sprintf('%dm %02ds', $m, $s);
        }
        return sprintf('%ds', $s);
    }
}
