<?php
declare(strict_types=1);

namespace Survos\FetchBundle\Paginate\Service;

use Psr\Log\LoggerInterface;
use Survos\FetchBundle\Paginate\Dto\PageRequest;
use Survos\FetchBundle\Paginate\Message\PaginatedFetchMessage;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\Service\JsonlStateService;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Kicks off (or resumes) a paginated fetch by dispatching the first
 * PaginatedFetchMessage. The handler chains the rest.
 *
 * Resume is database-free: the JSONL sidecar's "_resume" pointer (written by the
 * handler after each page) is replayed. Callers supply $first for cold starts.
 */
final class Paginator
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly JsonlStateService $stateService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $resetFiles absolute paths of satellite JSONL files (see
     *                                  PageFetchedEvent::$files) to truncate on a cold
     *                                  start, so they do not accumulate across reruns.
     * @return array{action:string,url?:string} what was dispatched ('started'|'resumed'|'already-complete')
     */
    public function start(
        string $sourceCode,
        string $jsonlPath,
        PageRequest $first,
        string $transport = 'async',
        bool $resume = true,
        array $resetFiles = [],
    ): array {
        if ($resume) {
            $state = $this->stateService->load($jsonlPath);
            if ($state->getStats()->isCompleted()) {
                $this->logger->info('paginate already complete', ['source' => $sourceCode, 'jsonl' => $jsonlPath]);

                return ['action' => 'already-complete'];
            }

            $pointer = $state->context('_resume');
            if (is_array($pointer) && isset($pointer['url']) && is_string($pointer['url'])) {
                $next = new PageRequest($pointer['url'], is_array($pointer['meta'] ?? null) ? $pointer['meta'] : []);
                $this->dispatch($sourceCode, $jsonlPath, $next, $transport);
                $this->logger->info('paginate resumed', ['source' => $sourceCode, 'url' => $next->url]);

                return ['action' => 'resumed', 'url' => $next->url];
            }
        }

        // Cold start: truncate the primary file (and any satellites) and reset the
        // sidecar, then dispatch page 1.
        JsonlWriter::open($jsonlPath, mode: 'w')->finish(markComplete: false);
        foreach ($resetFiles as $path) {
            JsonlWriter::open($path, mode: 'w')->finish(markComplete: false);
        }
        $this->dispatch($sourceCode, $jsonlPath, $first, $transport);
        $this->logger->info('paginate started', ['source' => $sourceCode, 'url' => $first->url]);

        return ['action' => 'started', 'url' => $first->url];
    }

    private function dispatch(string $sourceCode, string $jsonlPath, PageRequest $page, string $transport): void
    {
        $this->bus->dispatch(
            new PaginatedFetchMessage($sourceCode, $jsonlPath, $page->url, $page->meta, $transport),
            [new TransportNamesStamp([$transport])],
        );
    }
}
