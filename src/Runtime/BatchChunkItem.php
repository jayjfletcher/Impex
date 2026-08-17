<?php

declare(strict_types=1);

namespace JayI\Impex\Runtime;

/**
 * One unit of work yielded by a batch source.
 *
 * The key is what makes the item idempotent: `unique(batch_id, item_key)` means
 * a redelivered seed loses the insert race and exits, exactly as
 * `unique(run_id, sequence)` does for steps. Derive it from something stable in
 * the source data — a primary key or SKU, never a position.
 */
final readonly class BatchChunkItem
{
    public function __construct(
        public string $key,
        public mixed $payload = null,
    ) {}
}
