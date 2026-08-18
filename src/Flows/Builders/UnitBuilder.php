<?php

declare(strict_types=1);

namespace JayI\Impex\Flows\Builders;

use JayI\Impex\Enums\RollbackFailure;
use JayI\Impex\Runtime\Context;

/**
 * Groups steps under one rollback policy.
 *
 * Every step runs and is recorded exactly as `action()` would, so grouping
 * changes nothing about the forward path. What it changes is the rollback: the
 * group's steps share a failure policy, and can be rolled back together rather
 * than one at a time.
 */
final class UnitBuilder
{
    private readonly string $group;

    private RollbackFailure $onFailure = RollbackFailure::Halt;

    private bool $inParallel = false;

    /** @var array<int, ActionBuilder> */
    private array $steps = [];

    public function __construct(private readonly Context $context)
    {
        // Deterministic: derived from the group's position in the replay, not
        // from a random value, so it is identical on every drive.
        $this->group = $this->context->unitId();
    }

    /**
     * What to do when a rollback in this group itself fails.
     *
     * `Stop` (the default) halts the rollback and leaves the run for
     * inspection, because a half-completed rollback that keeps going can
     * compound the damage. `Continue` pushes through and reports at the end.
     */
    public function onRollbackFailure(RollbackFailure $policy): self
    {
        $this->onFailure = $policy;

        return $this;
    }

    /**
     * Roll the group back all at once instead of in reverse order.
     *
     * Only safe when the group's steps are independent — if releasing stock
     * before refunding a card matters, leave this off.
     */
    public function rollbackTogether(): self
    {
        $this->inParallel = true;

        return $this;
    }

    /**
     * Add a step to the group.
     */
    public function step(string $action, mixed ...$arguments): self
    {
        $this->steps[] = new ActionBuilder($this->context, $action, array_values($arguments));

        return $this;
    }

    /**
     * Register a rollback for the step most recently added.
     */
    public function undoWith(string $action, mixed ...$arguments): self
    {
        $step = end($this->steps);

        if ($step instanceof ActionBuilder) {
            $step->undoWith($action, ...$arguments);
        }

        return $this;
    }

    /**
     * Attempts allowed for the step most recently added.
     */
    public function tries(int $attempts): self
    {
        $step = end($this->steps);

        if ($step instanceof ActionBuilder) {
            $step->tries($attempts);
        }

        return $this;
    }

    /**
     * Run the group in order, returning each step's result.
     *
     * @return array<int, mixed>
     */
    public function run(): array
    {
        $results = [];

        foreach ($this->steps as $step) {
            $results[] = $step
                ->inUnit($this->group, $this->onFailure, $this->inParallel)
                ->run();
        }

        return $results;
    }
}
