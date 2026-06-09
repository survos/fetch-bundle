<?php
declare(strict_types=1);

namespace Survos\FetchBundle\Paginate\MessageHandler;

use Psr\Log\LoggerInterface;
use Survos\FetchBundle\Contract\RetryStrategyInterface;
use Survos\FetchBundle\Paginate\Event\PageFetchedEvent;
use Survos\FetchBundle\Paginate\Message\PaginatedFetchMessage;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\Service\JsonlStateService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetch one page (with retry/backoff), let a provider listener parse it, append the
 * rows to JSONL, checkpoint the sidecar, then dispatch the next page to $transport.
 *
 * No database — resume state lives in the JSONL sidecar under the "_resume" key.
 */
#[AsMessageHandler]
final class PaginatedFetchMessageHandler
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly MessageBusInterface $bus,
        private readonly JsonlStateService $stateService,
        private readonly RetryStrategyInterface $retry,
        private readonly LoggerInterface $logger,
        private readonly int $timeout = 30,
    ) {
    }

    public function __invoke(PaginatedFetchMessage $message): void
    {
        [$body, $status, $headers] = $this->fetchWithRetry($message);

        $data = json_decode($body, true);
        if (!is_array($data)) {
            $data = null;
        }

        $event = new PageFetchedEvent($message, $body, $data, $status, $headers);
        $this->dispatcher->dispatch($event);

        // Append this page's rows to the primary file. Mode 'a' so each page extends it.
        $writer = JsonlWriter::open($message->jsonlPath, mode: 'a');
        try {
            $this->appendRows($writer, $event->rows);

            // Persist the application's own context (page size, page number, …) verbatim,
            // plus bundle-managed keys (underscore-prefixed) used for resume.
            $context = $message->meta;
            $context['_source'] = $message->sourceCode;
            $context['_resume'] = $event->next !== null
                ? ['url' => $event->next->url, 'meta' => $event->next->meta]
                : null;
            $writer->putContext($context);
        } finally {
            // Do NOT mark complete here — only the final page (no $next) completes.
            $writer->finish(markComplete: false);
        }

        // Sibling files (satellites of the primary): no checkpoint of their own.
        foreach ($event->files as $path => $rows) {
            $sibling = JsonlWriter::open($path, mode: 'a');
            try {
                $this->appendRows($sibling, $rows);
            } finally {
                $sibling->finish(markComplete: false);
            }
        }

        $this->logger->info('paginate page stored', [
            'source' => $message->sourceCode,
            'url' => $message->url,
            'rows' => count($event->rows),
            'files' => array_map('count', $event->files),
            'hasNext' => $event->next !== null,
        ]);

        if ($event->next === null) {
            $this->stateService->markComplete($message->jsonlPath);
            $this->logger->info('paginate complete', [
                'source' => $message->sourceCode,
                'jsonl' => $message->jsonlPath,
            ]);

            return;
        }

        $stamps = [new TransportNamesStamp([$message->transport])];
        // Optional pacing between pages: delay delivery of the next message rather than
        // blocking the worker (honoured by the broker; e.g. doctrine available_at).
        $delayMs = (int) ($event->next->meta['delayMs'] ?? 0);
        if ($delayMs > 0) {
            $stamps[] = new DelayStamp($delayMs);
        }

        $this->bus->dispatch(
            new PaginatedFetchMessage(
                $message->sourceCode,
                $message->jsonlPath,
                $event->next->url,
                $event->next->meta,
                $message->transport,
            ),
            $stamps,
        );
    }

    /**
     * Append rows, honouring an optional "_token" dedup hint (popped before writing).
     *
     * @param list<array<string,mixed>> $rows
     */
    private function appendRows(JsonlWriter $writer, array $rows): void
    {
        foreach ($rows as $row) {
            $token = null;
            if (array_key_exists('_token', $row)) {
                $token = is_scalar($row['_token']) ? (string) $row['_token'] : null;
                unset($row['_token']);
            }
            $writer->write($row, $token);
        }
    }

    /**
     * @return array{0:string,1:int,2:array<string,list<string>>}
     */
    private function fetchWithRetry(PaginatedFetchMessage $message): array
    {
        /** @var array<string,string> $headers */
        $headers = is_array($message->meta['headers'] ?? null)
            ? $message->meta['headers']
            : ['Accept' => 'application/json'];

        $attempt = 0;
        while (true) {
            $attempt++;
            $status = null;
            try {
                $response = $this->httpClient->request('GET', $message->url, [
                    'headers' => $headers,
                    'timeout' => $this->timeout,
                ]);

                $status = $response->getStatusCode();
                if ($status < 400) {
                    // getContent(false): return body without throwing on non-2xx.
                    return [$response->getContent(false), $status, $response->getHeaders(false)];
                }
                // Non-2xx with a usable status code — decide on retry by status below.
                $error = new \RuntimeException(sprintf('HTTP %d fetching %s', $status, $message->url));
            } catch (\Throwable $e) {
                // Transport-level failure (DNS, connect, timeout) — no usable status.
                $error = $e;
                $status = null;
            }

            // Retry by status for HTTP errors (429/5xx), by exception for transport errors.
            // A client error like 400 is non-retryable and throws on the first attempt.
            $retryable = $status !== null
                ? $this->retry->shouldRetry($attempt, $status, null)
                : $this->retry->shouldRetry($attempt, null, $error);

            if ($retryable) {
                $this->backoff($attempt, $status, $status === null ? $error : null, $message);
                continue;
            }

            throw $error;
        }
    }

    private function backoff(int $attempt, ?int $status, ?\Throwable $e, PaginatedFetchMessage $message): void
    {
        $delayMs = $this->retry->getDelayMs($attempt, $status, $e);
        $this->logger->warning('paginate fetch retry', [
            'source' => $message->sourceCode,
            'url' => $message->url,
            'attempt' => $attempt,
            'status' => $status,
            'delayMs' => $delayMs,
            'error' => $e?->getMessage(),
        ]);
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }
}
