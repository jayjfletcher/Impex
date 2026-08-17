<?php

declare(strict_types=1);

namespace JayI\Impex\Contracts;

use JayI\Impex\Runtime\BatchChunk;

/**
 * Streams work into a batch, one resumable page at a time.
 *
 * Seeding a large source takes longer than a single invocation may live, so a
 * source is asked for one chunk at a time and hands back the cursor to resume
 * from. Keyset pagination maps onto this directly — as does Elasticsearch's
 * `search_after`, whose sort values are exactly this cursor.
 */
interface BatchSource
{
    /**
     * The next page of work after the cursor.
     *
     * Return BatchChunk::last() when the source is exhausted.
     */
    public function chunk(?string $cursor, int $size): BatchChunk;
}
