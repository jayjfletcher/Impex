<?php

declare(strict_types=1);

namespace JayI\Impex\Flows\Builders;

use DateTimeInterface;
use JayI\Impex\Enums\RollbackFailure;
use JayI\Impex\Enums\StepType;
use JayI\Impex\Runtime\Context;
use JayI\Impex\Runtime\StepDescriptor;

/**
 * Describes one action, and runs it as a recorded step.
 */
final class ActionBuilder
{
    /** @var array{action: string, arguments: array<int, mixed>}|null */
    private ?array $rollback = null;

    private int $maxAttempts = 1;

    private bool $continueOnFailure = false;

    private mixed $fallback = null;

    private ?DateTimeInterface $expiresAt = null;

    private ?string $unitId = null;

    private RollbackFailure $rollbackFailure = RollbackFailure::Halt;

    private bool $rollbackTogether = false;

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __construct(
        private readonly Context $context,
        private readonly string $action,
        private readonly array $arguments,
    ) {}

    /**
     * Register the rollback for this step.
     *
     * Captured when the forward step is recorded, so rollback never has to
     * replay the flow to discover it. Class-based rather than a closure for the
     * same reason — a closure cannot be stored.
     */
    public function undoWith(string $action, mixed ...$arguments): self
    {
        $this->rollback = [
            'action' => $action,
            'arguments' => array_values($arguments),
        ];

        return $this;
    }

    /**
     * How many times the step may be attempted before it fails terminally.
     */
    public function tries(int $attempts): self
    {
        $this->maxAttempts = max(1, $attempts);

        return $this;
    }

    /**
     * Treat a terminal failure as a non-event, returning the fallback instead
     * of unwinding the run.
     */
    public function continueOnFailure(mixed $fallback = null): self
    {
        $this->continueOnFailure = true;
        $this->fallback = $fallback;

        return $this;
    }

    /**
     * A deadline for this step alone.
     */
    public function expiresAt(DateTimeInterface $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    /**
     * Mark this step as part of a unit group, sharing its rollback policy.
     *
     * Called by UnitBuilder; there is no reason to call it directly.
     */
    public function inUnit(
        string $group,
        RollbackFailure $onFailure,
        bool $inParallel,
    ): self {
        $this->unitId = $group;
        $this->rollbackFailure = $onFailure;
        $this->rollbackTogether = $inParallel;

        return $this;
    }

    /**
     * The immutable description handed to the engine.
     */
    public function descriptor(): StepDescriptor
    {
        return new StepDescriptor(
            type: StepType::Action,
            name: $this->action,
            arguments: $this->arguments,
            rollback: $this->rollback,
            maxAttempts: $this->maxAttempts,
            continueOnFailure: $this->continueOnFailure,
            fallback: $this->fallback,
            expiresAt: $this->expiresAt,
            unitId: $this->unitId,
            rollbackFailure: $this->rollbackFailure,
            rollbackTogether: $this->rollbackTogether,
        );
    }

    /**
     * Resolve the step: return its recorded result, or schedule it and suspend.
     */
    public function run(): mixed
    {
        return $this->context->resolve($this->context->nextSequence(), $this->descriptor());
    }
}
