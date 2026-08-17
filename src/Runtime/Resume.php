<?php

declare(strict_types=1);

namespace JayI\Impex\Runtime;

/**
 * Returned by an action that ran out of time before it ran out of work.
 *
 * The engine stores the cursor on the step, releases the lease, and
 * re-dispatches the same step rather than completing or failing it. The step
 * keeps its sequence, so the replay is unaffected — a resumed step looks
 * exactly like a slow one to the flow that scheduled it.
 */
final readonly class Resume
{
    public function __construct(
        public ?string $cursor,
        public ?int $delaySeconds = null,
    ) {}

    public static function from(?string $cursor, ?int $delaySeconds = null): self
    {
        return new self($cursor, $delaySeconds);
    }
}
