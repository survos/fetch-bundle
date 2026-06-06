<?php
declare(strict_types=1);

namespace Survos\FetchBundle\Fetch;

use Psr\Log\LoggerInterface;
use Survos\FetchBundle\Contract\ConcurrentFetcherInterface;
use Survos\FetchBundle\Contract\DTO\FetchOptions;
use Survos\FetchBundle\Contract\RetryStrategyInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class SymfonyConcurrentFetcher implements ConcurrentFetcherInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function fetchMany(iterable $requests, FetchOptions $options): iterable
    {
        $retry = $options->retry;

        $queue = [];
        foreach ($requests as $key => $req) {
            $url = $req['url'] ?? null;
            if (!$url) {
                throw new \InvalidArgumentException('Request missing "url"');
            }
            $queue[$key] = [
                'url'     => $url,
                'method'  => $req['method']  ?? 'GET',
                'headers' => $req['headers'] ?? [],
                'body'    => $req['body']    ?? null,
                'attempt' => 0,
                'buffer'  => '',
            ];
        }

        /** @var array<int|string,array{response:ResponseInterface,attempt:int,buffer:string,meta:array}> $active */
        $active = [];

        $startNext = function () use (&$queue, &$active, $options): void {
            while (\count($active) < $options->concurrency && $queue !== []) {
                $key = array_key_first($queue);
                $meta = $queue[$key];
                unset($queue[$key]);

                $this->logger?->info(sprintf('LAUNCH #%s %s', (string)$key, $meta['url']));

                $response = $this->http->request($meta['method'], $meta['url'], [
                    'headers'      => $options->defaultHeaders + $meta['headers'],
                    'body'         => $meta['body'],
                    'timeout'      => $options->timeout,
                    // Try HTTP/2 multiplexing when supported by server
                    'http_version' => '2.0',
                ]);

                $active[$key] = [
                    'response' => $response,
                    'attempt'  => $meta['attempt'] + 1,
                    'buffer'   => '',
                    'meta'     => $meta,
                ];
            }
        };

        $startNext();

        while ($active !== []) {
            $responses = array_map(static fn($a) => $a['response'], $active);
            foreach ($this->http->stream($responses, $options->timeout) as $response => $chunk) {
                $foundKey = null;
                foreach ($active as $k => $a) {
                    if ($a['response'] === $response) { $foundKey = $k; break; }
                }
                if ($foundKey === null) {
                    continue;
                }

                if ($chunk->isTimeout()) {
                    continue;
                }

                if ($chunk->isLast()) {
                    $status  = 0;
                    $headers = [];
                    $body    = $active[$foundKey]['buffer'];

                    try {
                        $status  = $response->getStatusCode();
                        $headers = $response->getHeaders(false);
                    } catch (\Throwable $e) {
                        $this->logger?->warning(sprintf('ERROR  #%s %s attempt=%d: %s',
                            (string)$foundKey,
                            $active[$foundKey]['meta']['url'],
                            $active[$foundKey]['attempt'],
                            $e->getMessage()
                        ));
                        $this->handleRetryOrFail($foundKey, $active, $queue, $options, $retry, null, $e);
                        $startNext();
                        continue 2;
                    }

                    if ($retry instanceof RetryStrategyInterface && $retry->shouldRetry($active[$foundKey]['attempt'], $status, null)) {
                        $delayMs = $retry->getDelayMs($active[$foundKey]['attempt'], $status, null);
                        $this->logger?->info(sprintf('RETRY  #%s status=%d wait=%dms', (string)$foundKey, $status, $delayMs));
                        \usleep(max(0, $delayMs) * 1000);
                        $this->requeue($foundKey, $active, $queue);
                        $startNext();
                        continue 2;
                    }

                    $this->logger?->info(sprintf('DONE   #%s status=%d bytes=%d',
                        (string)$foundKey, $status, \strlen($body)));

                    unset($active[$foundKey]);
                    yield $foundKey => [
                        'status'  => $status,
                        'headers' => $headers,
                        'body'    => $body,
                    ];

                    $startNext();
                    continue 2;
                }

                if (!$chunk->isFirst()) {
                    $data = $chunk->getContent();
                    if ($data !== '') {
                        $active[$foundKey]['buffer'] .= $data;
                    }
                }
            }

            $startNext();
        }
    }

    private function handleRetryOrFail(
        int|string $key,
        array &$active,
        array &$queue,
        FetchOptions $options,
        ?RetryStrategyInterface $retry,
        ?int $status,
        ?\Throwable $e
    ): void {
        $attempt = $active[$key]['attempt'];
        $meta    = $active[$key]['meta'];
        unset($active[$key]);

        if ($retry instanceof RetryStrategyInterface && $retry->shouldRetry($attempt, $status, $e)) {
            $delayMs = $retry->getDelayMs($attempt, $status, $e);
            \usleep(max(0, $delayMs) * 1000);
            $meta['attempt'] = $attempt; // will increment when relaunched
            $queue[$key] = $meta;
            return;
        }

        $message = 'Concurrent fetch failed';
        if ($status !== null) { $message .= " (status=$status)"; }
        if ($e) { $message .= ': ' . $e->getMessage(); }
        throw new \RuntimeException($message, previous: $e);
    }

    private function requeue(int|string $key, array &$active, array &$queue): void
    {
        $meta = $active[$key]['meta'];
        $meta['attempt'] = $active[$key]['attempt'];
        unset($active[$key]);
        $queue[$key] = $meta;
    }
}
