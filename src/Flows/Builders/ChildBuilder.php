<?php

declare(strict_types=1);

namespace JayI\Impex\Flows\Builders;

use JayI\Impex\Enums\ChildClosePolicy;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Enums\StepType;
use JayI\Impex\Exceptions\HistoryMismatchException;
use JayI\Impex\Exceptions\StepFailedException;
use JayI\Impex\Models\RunStep;
use JayI\Impex\Runtime\Context;

/**
 * Runs another flow as a child of this one.
 *
 * The child is a Run in its own right — its own history, its own rollback,
 * its own row in the dashboard — linked back by `parent_run_id`. The parent
 * parks on it exactly as it would on any other step, so a child that takes a
 * week costs the parent nothing while it waits.
 *
 * Use this rather than batch() or fanOut() when the work is a *different*
 * workflow, not more of the same one.
 */
final class ChildBuilder
{
    private ChildClosePolicy $closePolicy = ChildClosePolicy::Fail;

    /** @var array<string, string> */
    private array $tags = [];

    private bool $detached = false;

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __construct(
        private readonly Context $context,
        private readonly string $flow,
        private readonly array $arguments,
    ) {}

    /**
     * What happens to the child if the parent finishes first.
     */
    public function closePolicy(ChildClosePolicy $policy): self
    {
        $this->closePolicy = $policy;

        return $this;
    }

    /**
     * Start the child and carry on without waiting for it.
     *
     * The step completes as soon as the child is created, returning its id.
     * Pair with a close policy other than Fail, or the parent may outlive a
     * child it never checked.
     */
    public function detached(): self
    {
        $this->detached = true;

        return $this;
    }

    /**
     * Tags to attach to the child run.
     *
     * @param  array<string, string>  $tags
     */
    public function withTags(array $tags): self
    {
        $this->tags = $tags;

        return $this;
    }

    /**
     * Resolve the child: return its result, or start it and suspend.
     */
    public function run(): mixed
    {
        $sequence = $this->context->nextSequence();
        $step = $this->context->step($sequence);

        if (! $step instanceof RunStep) {
            $this->context->engine()->startChild(
                $this->context->run,
                $sequence,
                $this->flow,
                $this->arguments,
                $this->closePolicy,
                $this->tags,
                $this->detached,
            );

            $this->context->suspend();
        }

        if ($step->type !== StepType::Child || $step->name !== $this->flow) {
            throw HistoryMismatchException::at(
                $sequence,
                $step->type->value.':'.$step->name,
                StepType::Child->value.':'.$this->flow,
            );
        }

        return match ($step->status) {
            StepStatus::Completed => $this->context->engine()->payloads()
                ->get($step->result, $step->result_artifact_id),
            StepStatus::Failed => throw StepFailedException::for(
                $sequence,
                $step->name,
                is_array($step->error) && is_string($step->error['message'] ?? null)
                    ? $step->error['message']
                    : null,
            ),
            default => $this->context->suspend(),
        };
    }
}
