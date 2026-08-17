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
 * Seeds a batch from its source, one resumable page at a time.
 *
 * Walking a large source takes longer than a single invocation may live, so
 * this job stops before the ceiling and re-dispatches itself from the cursor it
 * just stored.
 */
final class SeedBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $batchId,
        public readonly ?string $cursor = null,
    ) {}

    public function handle(BatchRunner $batches): void
    {
        $batches->seed($this->batchId, $this->cursor);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['impex', 'batch:'.$this->batchId];
    }
}
