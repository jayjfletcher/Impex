<?php

declare(strict_types=1);

namespace JayI\Impex\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use JayI\Impex\Runtime\BatchRunner;

/**
 * Runs one batch item's action.
 *
 * Carries an identifier only. Item state lives outside the replay log, so this
 * never touches a run's history however many items the batch holds.
 */
final class ProcessBatchItem implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $itemId) {}

    public function handle(BatchRunner $batches): void
    {
        $batches->processItem($this->itemId);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['impex', 'batch-item:'.$this->itemId];
    }
}
