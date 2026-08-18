<?php

declare(strict_types=1);

namespace JayI\Impex\Runtime;

/**
 * What one sweep did.
 */
final readonly class SweepReport
{
    public function __construct(
        public int $timers = 0,
        public int $leases = 0,
        public int $batches = 0,
        public int $expiredSteps = 0,
        public int $expiredRuns = 0,
    ) {}

    /**
     * Whether the sweep found anything to do.
     */
    public function idle(): bool
    {
        return $this->total() === 0;
    }

    public function total(): int
    {
        return $this->timers + $this->leases + $this->batches + $this->expiredSteps + $this->expiredRuns;
    }

    public function summary(): string
    {
        return sprintf(
            '%d timer(s) fired, %d lease(s) reclaimed, %d batch(es) finalised, '.
            '%d step(s) and %d run(s) past their deadline.',
            $this->timers,
            $this->leases,
            $this->batches,
            $this->expiredSteps,
            $this->expiredRuns,
        );
    }
}
