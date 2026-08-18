<?php

declare(strict_types=1);

namespace JayI\Impex\Flows\Builders;

use JayI\Impex\Enums\ParallelFailure;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Exceptions\StepFailedException;
use JayI\Impex\Models\RunStep;
use JayI\Impex\Runtime\Context;
use JayI\Impex\Runtime\StepDescriptor;

/**
 * Runs several actions concurrently.
 *
 * Unlike a single action, the block must schedule *every* unrecorded branch
 * before it suspends — otherwise the run would serialise one branch per drive.
 */
final class ParallelBuilder
{
    /** @var array<int, StepDescriptor> */
    private array $branches = [];

    private ParallelFailure $failurePolicy = ParallelFailure::FailFast;

    public function __construct(private readonly Context $context) {}

    /**
     * Add a branch to the block.
     */
    public function action(string $action, mixed ...$arguments): self
    {
        $this->branches[] = (new ActionBuilder($this->context, $action, array_values($arguments)))->descriptor();

        return $this;
    }

    /**
     * Add a pre-built branch, so a branch can carry its own rollback or
     * retry policy.
     */
    public function add(ActionBuilder $builder): self
    {
        $this->branches[] = $builder->descriptor();

        return $this;
    }

    /**
     * Fail the block as soon as any branch fails.
     */
    public function failFast(): self
    {
        return $this->failurePolicy(ParallelFailure::FailFast);
    }

    public function failurePolicy(ParallelFailure $policy): self
    {
        $this->failurePolicy = $policy;

        return $this;
    }

    /**
     * Resolve the block, returning branch results in declaration order.
     *
     * @return array<int, mixed>
     */
    public function run(): array
    {
        // Sequences are allocated for the whole block up front so that the
        // block's position in the replay is stable regardless of which branches
        // have finished.
        $sequences = [];

        foreach ($this->branches as $index => $branch) {
            $sequences[$index] = $this->context->nextSequence();
        }

        $results = [];
        $failures = [];
        $pending = false;

        foreach ($this->branches as $index => $branch) {
            $sequence = $sequences[$index];
            $step = $this->context->step($sequence);

            if (! $step instanceof RunStep) {
                $this->context->engine()->scheduleStep($this->context->run, $sequence, $branch);
                $pending = true;

                continue;
            }

            match ($step->status) {
                StepStatus::Completed => $results[$index] = $this->context->engine()->payloads()
                    ->get($step->result, $step->result_artifact_id),
                StepStatus::Skipped => $results[$index] = $branch->fallback,
                StepStatus::Failed => $branch->continueOnFailure
                    ? $results[$index] = $branch->fallback
                    : $failures[$index] = $step,
                default => $pending = true,
            };
        }

        if ($failures !== [] && $this->failurePolicy === ParallelFailure::FailFast) {
            $this->throwFirst($failures, $sequences);
        }

        if ($pending) {
            $this->context->suspend();
        }

        if ($failures !== []) {
            $this->throwFirst($failures, $sequences);
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * @param  array<int, RunStep>  $failures
     * @param  array<int, int>  $sequences
     */
    private function throwFirst(array $failures, array $sequences): never
    {
        $index = (int) array_key_first($failures);
        $step = $failures[$index];

        $error = $step->error;
        $message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : null;

        throw StepFailedException::for($sequences[$index], $step->name, $message);
    }
}
