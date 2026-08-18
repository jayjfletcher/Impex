<?php

declare(strict_types=1);

namespace JayI\Impex\Contracts;

use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunStep;

/**
 * Decides what a failed run unwinds, and in what order.
 *
 * The shipped strategy walks completed steps in reverse, rolling back one at a
 * time unless their unit asked to go together. Bind your own to change the
 * order, batch differently, or refuse to unwind past a point.
 */
interface RollbackStrategy
{
    /**
     * Queue the next rollback for a run that is unwinding.
     *
     * Called on every drive while the run is rolling back. Return false when
     * there is nothing left to undo — the engine then finishes the run.
     */
    public function next(Run $run): bool;

    /**
     * Whether a failed rollback should halt the unwind rather than push past it.
     */
    public function halts(RunStep $rollbackStep): bool;
}
