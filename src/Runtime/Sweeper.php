<?php

declare(strict_types=1);

namespace JayI\Impex\Runtime;

/**
 * The engine's periodic pass.
 *
 * Four jobs, each of which the rest of the engine deliberately leaves undone
 * because it cannot be done in-process:
 *
 * - fire timers, because the queue's delay ceiling is far shorter than the
 *   waits a flow can express
 * - reclaim leases, because a killed invocation cannot release its own
 * - enforce deadlines, because a step inside an upstream call cannot check a
 *   clock
 * - finalise batches whose completion check lost its throttle lock
 *
 * Exposed as a class rather than living in the command so it can be called from
 * a job, a test, or a health check.
 */
final class Sweeper
{
    public function __construct(
        private readonly Waits $waits,
        private readonly BatchRunner $batches,
    ) {}

    /**
     * Run every pass, reporting what each one did.
     */
    public function sweep(?int $limit = null): SweepReport
    {
        $deadlines = $this->waits->enforceDeadlines();

        return new SweepReport(
            timers: $this->waits->sweep($limit),
            leases: $this->waits->reclaimLeases(),
            batches: $this->batches->sweep(),
            expiredSteps: $deadlines['steps'],
            expiredRuns: $deadlines['runs'],
        );
    }
}
