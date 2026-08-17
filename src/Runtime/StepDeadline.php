<?php

declare(strict_types=1);

namespace JayI\Impex\Runtime;

use Illuminate\Support\Carbon;

/**
 * How long the current invocation may keep working.
 *
 * Derived from the configured step ceiling, less a margin so an action has
 * room to checkpoint and return after it decides to yield. Lambda kills the
 * invocation at 900 seconds without warning; this is the warning.
 */
final readonly class StepDeadline
{
    public function __construct(
        public Carbon $expiresAt,
        public int $marginSeconds,
    ) {}

    public static function in(int $seconds, int $marginSeconds = 30): self
    {
        return new self(Carbon::now()->addSeconds($seconds), $marginSeconds);
    }

    /**
     * Whether the invocation should checkpoint and yield now.
     */
    public function reached(): bool
    {
        return $this->secondsRemaining() <= $this->marginSeconds;
    }

    public function secondsRemaining(): int
    {
        return max(0, (int) Carbon::now()->diffInSeconds($this->expiresAt, false));
    }
}
