<?php

declare(strict_types=1);

namespace JayI\Impex\Enums;

enum StepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Undone = 'undone';
    case Skipped = 'skipped';

    /**
     * Whether a claim may be taken on a step in this state.
     */
    public function isClaimable(): bool
    {
        return match ($this) {
            self::Pending, self::Running, self::Failed => true,
            default => false,
        };
    }
}
