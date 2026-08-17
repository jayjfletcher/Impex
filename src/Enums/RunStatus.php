<?php

declare(strict_types=1);

namespace JayI\Impex\Enums;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Waiting = 'waiting';
    case Compensating = 'compensating';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Whether the run has reached a terminal state.
     */
    public function isFinished(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Whether the run may still be driven forward.
     */
    public function isActive(): bool
    {
        return ! $this->isFinished();
    }
}
