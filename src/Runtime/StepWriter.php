<?php

declare(strict_types=1);

namespace JayI\Impex\Runtime;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use JayI\Impex\Enums\ArtifactKind;
use JayI\Impex\Enums\StepPhase;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Enums\StepType;
use JayI\Impex\Enums\TimerKind;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunStep;
use JayI\Impex\Models\Timer;
use JayI\Impex\Support\PayloadStore;

/**
 * Writes the replay history.
 *
 * Every recorded step goes through here, so the rules that make replay safe —
 * payload offload above the inline threshold, the rollback captured at record
 * time, the default deadline — are applied in exactly one place rather than at
 * each call site.
 */
final class StepWriter
{
    public function __construct(
        private readonly PayloadStore $payloads,
        private readonly EngineOptions $options,
    ) {}

    /**
     * Record a step the engine is about to schedule.
     */
    public function write(
        Run $run,
        StepPhase $phase,
        int $sequence,
        StepDescriptor $descriptor,
        ?int $undoes = null,
    ): RunStep {
        $stored = $this->payloads->put($descriptor->arguments, ArtifactKind::Payload, [
            'run_id' => $run->getKey(),
        ]);

        return RunStep::query()->create([
            'run_id' => $run->getKey(),
            'phase' => $phase,
            'sequence' => $sequence,
            'type' => $descriptor->type,
            'name' => $descriptor->name,
            'status' => StepStatus::Pending,
            'input' => $stored['inline'],
            'input_artifact_id' => $stored['artifact_id'],
            // Captured now, so unwinding never has to replay the flow to learn
            // what to undo.
            'rollback' => $descriptor->rollback === null ? null : $descriptor->rollback + [
                'on_failure' => $descriptor->rollbackFailure->value,
                'together' => $descriptor->rollbackTogether,
            ],
            'max_attempts' => $descriptor->maxAttempts,
            'undoes_sequence' => $undoes,
            'unit_id' => $descriptor->unitId,
            'expires_at' => $descriptor->expiresAt ?? $this->options->defaultStepDeadline(),
            'queued_at' => Carbon::now(),
        ]);
    }

    /**
     * Record a step that is already finished, with its result.
     */
    public function complete(
        Run $run,
        int $sequence,
        StepType $type,
        string $name,
        mixed $result,
    ): RunStep {
        $stored = $this->payloads->put($result, ArtifactKind::Result, ['run_id' => $run->getKey()]);

        return RunStep::query()->create([
            'run_id' => $run->getKey(),
            'phase' => StepPhase::Forward,
            'sequence' => $sequence,
            'type' => $type,
            'name' => $name,
            'status' => StepStatus::Completed,
            'result' => $stored['inline'],
            'result_artifact_id' => $stored['artifact_id'],
            'attempts' => 1,
            'max_attempts' => 1,
            'completed_at' => Carbon::now(),
        ]);
    }

    /**
     * Resolve a step that was already recorded, with its result.
     */
    public function resolve(RunStep $step, mixed $result): void
    {
        $stored = $this->payloads->put($result, ArtifactKind::Result, ['run_id' => $step->run_id]);

        $step->update([
            'status' => StepStatus::Completed,
            'result' => $stored['inline'],
            'result_artifact_id' => $stored['artifact_id'],
            'completed_at' => Carbon::now(),
        ]);
    }

    /**
     * Record a step the run is now parked on.
     */
    public function pending(Run $run, int $sequence, StepType $type, string $name): RunStep
    {
        return RunStep::query()->create([
            'run_id' => $run->getKey(),
            'phase' => StepPhase::Forward,
            'sequence' => $sequence,
            'type' => $type,
            'name' => $name,
            'status' => StepStatus::Pending,
            'max_attempts' => 1,
            'queued_at' => Carbon::now(),
        ]);
    }

    /**
     * Record a future wake-up.
     *
     * A timer row rather than a delayed job, because the queue's delay ceiling
     * is far shorter than the waits a flow can express.
     */
    public function timer(Run $run, int $sequence, TimerKind $kind, DateTimeInterface $wakeAt): void
    {
        Timer::query()->create([
            'run_id' => $run->getKey(),
            'phase' => StepPhase::Forward,
            'sequence' => $sequence,
            'kind' => $kind,
            'wake_at' => $wakeAt,
        ]);
    }
}
