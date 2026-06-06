<?php
declare(strict_types=1);

namespace Survos\FetchBundle\Contract\Orchestrate;

/**
 * Where extracted items land.
 * Optional JSON-lines adapters can append extracted items in order.
 */
interface IngestTargetInterface
{
    /** Called when a unit (e.g., block) is ready with its extracted items. */
    public function provide(int $unitIndex, array $items): void;

    /** Called when ingestion is done (flush/close finalization). */
    public function finish(): void;
}
