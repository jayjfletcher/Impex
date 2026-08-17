<?php

declare(strict_types=1);

namespace JayI\Impex\Flows\Concerns;

use JayI\Impex\Runtime\Resume;
use JayI\Impex\Runtime\StepDeadline;

/**
 * The action side of the resume protocol.
 *
 * Read the cursor to pick up where the last invocation stopped, check
 * shouldYield() at a natural checkpoint, and return yieldTo() to be
 * re-dispatched. Returning anything else completes the step.
 */
trait CanResume
{
    private ?string $impexCursor = null;

    private ?StepDeadline $impexDeadline = null;

    public function withCheckpoint(?string $cursor, StepDeadline $deadline): void
    {
        $this->impexCursor = $cursor;
        $this->impexDeadline = $deadline;
    }

    /**
     * The checkpoint the previous invocation yielded, or null on first run.
     */
    protected function cursor(): ?string
    {
        return $this->impexCursor;
    }

    /**
     * Whether this invocation is close enough to its ceiling to checkpoint.
     */
    protected function shouldYield(): bool
    {
        return $this->impexDeadline?->reached() ?? false;
    }

    /**
     * Seconds left before this invocation must stop.
     */
    protected function secondsRemaining(): int
    {
        return $this->impexDeadline?->secondsRemaining() ?? 0;
    }

    /**
     * Checkpoint and ask the engine to re-dispatch this step.
     */
    protected function yieldTo(?string $cursor, ?int $delaySeconds = null): Resume
    {
        return Resume::from($cursor, $delaySeconds);
    }
}
