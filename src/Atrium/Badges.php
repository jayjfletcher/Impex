<?php

declare(strict_types=1);

namespace JayI\Impex\Atrium;

use JayI\Impex\Enums\Direction;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\StepStatus;

/**
 * Maps Impex's enums onto Atrium badge variants.
 *
 * Kept in one place so the pages and the dashboard widgets colour the same
 * state identically.
 */
final class Badges
{
    public static function forRun(RunStatus $status): string
    {
        return match ($status) {
            RunStatus::Completed => 'success',
            RunStatus::Failed => 'danger',
            RunStatus::Cancelled => 'neutral',
            RunStatus::Waiting, RunStatus::RollingBack => 'warning',
            RunStatus::Running, RunStatus::Pending => 'info',
        };
    }

    public static function forStep(StepStatus $status): string
    {
        return match ($status) {
            StepStatus::Completed => 'success',
            StepStatus::Failed => 'danger',
            StepStatus::Running, StepStatus::Pending => 'info',
            StepStatus::Undone, StepStatus::Skipped => 'neutral',
        };
    }

    public static function forDirection(Direction $direction): string
    {
        return match ($direction) {
            Direction::Inbound => 'info',
            Direction::Outbound => 'primary',
        };
    }

    public static function forBoolean(bool $value): string
    {
        return $value ? 'success' : 'neutral';
    }
}
