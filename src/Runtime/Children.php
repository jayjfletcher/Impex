<?php

declare(strict_types=1);

namespace JayI\Impex\Runtime;

use Illuminate\Support\Carbon;
use JayI\Impex\Enums\ArtifactKind;
use JayI\Impex\Enums\ChildClosePolicy;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\RunTrigger;
use JayI\Impex\Enums\StepPhase;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Enums\StepType;
use JayI\Impex\Flows\FlowRegistry;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunStep;
use JayI\Impex\Support\PayloadStore;
use Throwable;

/**
 * Runs flows from flows.
 *
 * A child is a run in its own right — own history, own rollback, own row —
 * linked back by `parent_run_id`. The parent parks on it like any other step,
 * so a child that takes a week costs the parent nothing while it waits.
 */
final class Children
{
    public function __construct(
        private readonly FlowRegistry $flows,
        private readonly PayloadStore $payloads,
        private readonly StepWriter $steps,
        private readonly JobRouter $jobs,
    ) {}

    /**
     * Record the parent's step and start the child.
     *
     * @param  array<int, mixed>  $arguments
     * @param  array<string, string>  $tags
     */
    public function start(
        Run $run,
        int $sequence,
        string $flow,
        array $arguments,
        ChildClosePolicy $closePolicy,
        array $tags,
        bool $detached,
    ): Run {
        $stored = $this->payloads->put($arguments, ArtifactKind::Payload, ['run_id' => $run->getKey()]);

        $step = $this->steps->write(
            $run,
            StepPhase::Forward,
            $sequence,
            new StepDescriptor(type: StepType::Child, name: $flow, arguments: $arguments),
        );

        $child = Run::query()->create([
            'flow' => $flow,
            'flow_class' => $this->flows->class($flow),
            'flow_version' => $run->flow_version,
            'status' => RunStatus::Pending,
            'trigger' => RunTrigger::Child,
            'input' => $stored['inline'],
            'input_artifact_id' => $stored['artifact_id'],
            'tags' => $tags === [] ? null : $tags,
            'parent_run_id' => $run->getKey(),
            'parent_sequence' => $sequence,
            'queue_connection' => $run->queue_connection,
            'queue' => $run->queue,
            'close_policy' => $closePolicy,
        ]);

        if ($detached) {
            // Fire and forget: resolve the step now, and wake the parent
            // ourselves — the child will not notify a step already resolved.
            $this->steps->resolve($step, ['run_id' => (string) $child->getKey(), 'detached' => true]);

            $this->jobs->drive($run);
        }

        $this->jobs->drive($child);

        return $child;
    }

    /**
     * Hand a finished child's outcome to the step its parent parked on.
     *
     * @param  array{inline: array<string, mixed>|null, artifact_id: string|null}|null  $stored
     */
    public function notifyParent(Run $run, ?array $stored, ?Throwable $error): void
    {
        if ($run->parent_run_id === null || $run->parent_sequence === null) {
            return;
        }

        $parent = Run::query()->find($run->parent_run_id);

        if (! $parent instanceof Run) {
            return;
        }

        $step = RunStep::query()
            ->where('run_id', $parent->getKey())
            ->where('phase', StepPhase::Forward)
            ->where('sequence', $run->parent_sequence)
            ->where('type', StepType::Child)
            ->first();

        // A detached child's step is already resolved, so there is nothing to
        // hand back and nobody waiting for it.
        if (! $step instanceof RunStep || $step->status !== StepStatus::Pending) {
            return;
        }

        if ($error instanceof Throwable || $run->status === RunStatus::Failed) {
            $step->update([
                'status' => StepStatus::Failed,
                'error' => $error instanceof Throwable ? Failure::describe($error) : $run->error,
                'completed_at' => Carbon::now(),
            ]);
        } else {
            $step->update([
                'status' => StepStatus::Completed,
                'result' => $stored['inline'] ?? null,
                'result_artifact_id' => $stored['artifact_id'] ?? null,
                'completed_at' => Carbon::now(),
            ]);
        }

        $this->jobs->drive($parent);
    }

    /**
     * Apply each child's close policy when its parent finishes.
     */
    public function close(Run $run): void
    {
        $children = Run::query()
            ->where('parent_run_id', $run->getKey())
            ->where('close_policy', ChildClosePolicy::Cancel)
            ->active()
            ->get();

        foreach ($children as $child) {
            $child->update([
                'status' => RunStatus::Cancelled,
                'error' => ['message' => 'Cancelled because the parent run finished.'],
                'finished_at' => Carbon::now(),
            ]);
        }
    }
}
