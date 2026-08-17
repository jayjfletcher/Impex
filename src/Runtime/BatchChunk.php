<?php

declare(strict_types=1);

namespace JayI\Impex\Runtime;

/**
 * A page of work from a batch source, plus the cursor to resume after it.
 *
 * Sources return a cursor rather than a generator because PHP generators cannot
 * be serialized across invocations: seeding a large source will span more
 * invocations than any one of them may live for.
 */
final readonly class BatchChunk
{
    /**
     * @param  array<int, BatchChunkItem>  $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor = null,
        public bool $complete = false,
    ) {}

    /**
     * @param  array<int, BatchChunkItem>  $items
     */
    public static function of(array $items, ?string $nextCursor): self
    {
        return new self($items, $nextCursor, false);
    }

    /**
     * The source has no more work.
     *
     * @param  array<int, BatchChunkItem>  $items
     */
    public static function last(array $items = []): self
    {
        return new self($items, null, true);
    }
}
