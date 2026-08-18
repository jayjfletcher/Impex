<?php

declare(strict_types=1);

namespace JayI\Impex\Runtime;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use JayI\Impex\Contracts\RollbackStrategy;
use JayI\Impex\Enums\RollbackFailure;
use JayI\Impex\Enums\StepPhase;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Enums\StepType;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunStep;

/**
 * Unwinds a failed run, newest completed step first.
 *
 * Rollbacks live in their own phase, with their own sequence space, so they
 * never collide with the forward history the replay reads. Each one's action
 * and arguments were captured when the forward step was recorded, which is why
 * unwinding never has to replay the flow — by the time a run is failing, the
 * flow may already be the thing that diverged.
 */
final class Rollbacks implements RollbackStrategy
{
    public function __construct(
        private readonly StepWriter $steps,
        private readonly JobRouter $jobs,
    ) {}

    /**
     * Queue the next rollback, or report that there is nothing left to undo.
     */
    public function next(Run $run): bool
    {
        // A halted unwind is finished, not paused: the engine fails the run
        // and leaves it partly undone for inspection.
        if (! $this->settleFailed($run)) {
            return false;
        }

        $target = $this->nextTarget($run);

        if (! $target instanceof RunStep) {
            return false;
        }

        $sequence = (int) RunStep::query()
            ->where('run_id', $run->getKey())
            ->where('phase', StepPhase::Rollback)
            ->count();

        foreach ($this->group($run, $target) as $item) {
            /** @var array{action: string, arguments: array<int, mixed>} $rollback */
            $rollback = $item->rollback;

            $step = $this->steps->write(
                $run,
                StepPhase::Rollback,
                $sequence++,
                new StepDescriptor(
                    type: StepType::Rollback,
                    name: $rollback['action'],
                    arguments: $rollback['arguments'],
                ),
                $item->sequence,
            );

            $this->jobs->step($run, $step);
        }

        return true;
    }

    /**
     * Whether a failed rollback halts the unwind.
     *
     * Halting is the default, because a half-completed unwind that keeps going
     * can compound the damage.
     */
    public function halts(RunStep $rollbackStep): bool
    {
        $target = RunStep::query()
            ->where('run_id', $rollbackStep->run_id)
            ->where('phase', StepPhase::Forward)
            ->where('sequence', $rollbackStep->undoes_sequence)
            ->first();

        if (! $target instanceof RunStep || ! is_array($target->rollback)) {
            return true;
        }

        return ($target->rollback['on_failure'] ?? RollbackFailure::Halt->value)
            === RollbackFailure::Halt->value;
    }

    /**
     * Deal with a rollback that failed before choosing the next target.
     *
     * Without this the same target is selected again on the next drive, because
     * it is still un-undone — an endless unwind loop.
     *
     * @return bool false when the unwind halted and the run should fail
     */
    private function settleFailed(Run $run): bool
    {
        $failed = RunStep::query()
            ->where('run_id', $run->getKey())
            ->where('phase', StepPhase::Rollback)
            ->where('status', StepStatus::Failed)
            ->orderByDesc('sequence')
            ->first();

        if (! $failed instanceof RunStep) {
            return true;
        }

        if ($this->halts($failed)) {
            $run->update([
                'error' => ($run->error ?? []) + [
                    'rollback' => sprintf(
                        'Unwind halted: the rollback [%s] failed. The run is left partly undone for '.
                        'inspection. Set RollbackFailure::Continue on the unit to push through instead.',
                        $failed->name,
                    ),
                ],
            ]);

            return false;
        }

        // Continue: give up on this one, record that we did, and move past it.
        $failed->update(['status' => StepStatus::Skipped]);

        RunStep::query()
            ->where('run_id', $run->getKey())
            ->where('phase', StepPhase::Forward)
            ->where('sequence', $failed->undoes_sequence)
            ->update(['undone' => true]);

        return true;
    }

    private function nextTarget(Run $run): ?RunStep
    {
        return $this->undoable($run)->orderByDesc('sequence')->first();
    }

    /**
     * The steps to unwind in this pass.
     *
     * One at a time, unless the target's unit asked to go together.
     *
     * @return Collection<int, RunStep>
     */
    private function group(Run $run, RunStep $target): Collection
    {
        if ($target->unit_id === null || ! $this->togetherWithin($target)) {
            return new Collection([$target]);
        }

        return $this->undoable($run)
            ->where('unit_id', $target->unit_id)
            ->orderByDesc('sequence')
            ->get();
    }

    /**
     * @return Builder<RunStep>
     */
    private function undoable(Run $run): Builder
    {
        return RunStep::query()
            ->where('run_id', $run->getKey())
            ->where('phase', StepPhase::Forward)
            ->where('status', StepStatus::Completed)
            ->where('undone', false)
            ->whereNotNull('rollback');
    }

    private function togetherWithin(RunStep $step): bool
    {
        return is_array($step->rollback) && ($step->rollback['together'] ?? false) === true;
    }
}
