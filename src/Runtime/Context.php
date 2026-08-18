<?php

declare(strict_types=1);

namespace JayI\Impex\Runtime;

use Closure;
use DateTimeInterface;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Enums\StepType;
use JayI\Impex\Exceptions\HistoryMismatchException;
use JayI\Impex\Exceptions\SignalTimeoutException;
use JayI\Impex\Exceptions\StepFailedException;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunStep;

/**
 * The replay cursor for one drive of one run.
 *
 * Every DSL call a flow makes is keyed by its position in the replay. A step
 * already recorded returns its stored result; an unrecorded one is scheduled
 * and the replay suspends. That ordering is the identity of a step, which is
 * why handle() must be deterministic.
 */
final class Context
{
    private int $cursor = 0;

    private bool $waiting = false;

    /** @var array<string, string> */
    private array $tags = [];

    /**
     * @param  array<int, RunStep>  $history  keyed by sequence
     */
    public function __construct(
        public readonly Run $run,
        private readonly array $history,
        private readonly Engine $engine,
    ) {}

    /**
     * Claim the next sequence in replay order.
     */
    public function nextSequence(): int
    {
        return $this->cursor++;
    }

    /**
     * The recorded step at a sequence, if the run has reached it before.
     */
    public function step(int $sequence): ?RunStep
    {
        return $this->history[$sequence] ?? null;
    }

    /**
     * Resolve one operation: return its recorded result, or schedule it and
     * suspend the replay.
     */
    public function resolve(int $sequence, StepDescriptor $descriptor): mixed
    {
        $step = $this->step($sequence);

        if (! $step instanceof RunStep) {
            $this->engine->scheduleStep($this->run, $sequence, $descriptor);

            $this->suspend();
        }

        $this->assertMatches($sequence, $step, $descriptor);

        return match ($step->status) {
            StepStatus::Completed => $this->engine->payloads()->get($step->result, $step->result_artifact_id),
            StepStatus::Skipped => $descriptor->fallback,
            StepStatus::Failed => $descriptor->continueOnFailure
                ? $descriptor->fallback
                : throw StepFailedException::for($sequence, $step->name, $this->errorMessage($step)),
            default => $this->suspend(),
        };
    }

    /**
     * Record a value that cannot be recomputed deterministically.
     */
    public function sideEffect(string $key, Closure $callback): mixed
    {
        $sequence = $this->nextSequence();
        $step = $this->step($sequence);

        if ($step instanceof RunStep) {
            $this->assertMatches($sequence, $step, new StepDescriptor(StepType::SideEffect, $key));

            return $this->engine->payloads()->get($step->result, $step->result_artifact_id);
        }

        $value = $callback();

        $this->engine->recordSideEffect($this->run, $sequence, $key, $value);

        return $value;
    }

    /**
     * Wait for an externally delivered signal.
     */
    public function awaitSignal(
        string $name,
        ?DateTimeInterface $timeout = null,
        bool $failOnTimeout = false,
        mixed $default = null,
    ): mixed {
        $sequence = $this->nextSequence();
        $step = $this->step($sequence);

        if ($step instanceof RunStep) {
            $this->assertMatches($sequence, $step, new StepDescriptor(StepType::Signal, $name));

            if ($step->status === StepStatus::Completed) {
                return $this->engine->payloads()->get($step->result, $step->result_artifact_id);
            }

            // Skipped marks a wait the timer closed. Completing with null
            // instead would be indistinguishable from a signal whose payload
            // really was null.
            if ($step->status === StepStatus::Skipped) {
                if ($failOnTimeout) {
                    throw SignalTimeoutException::name($name, $sequence);
                }

                return $default;
            }
        }

        // A signal delivered before the run reached the wait is held, so it is
        // consumed here rather than lost.
        $held = $this->engine->consumeSignal($this->run, $name, $sequence, $step);

        if ($held !== null) {
            return $held['payload'];
        }

        if (! $step instanceof RunStep) {
            $this->engine->recordSignalWait($this->run, $sequence, $name, $timeout);
        }

        $this->waiting = true;

        $this->suspend();
    }

    /**
     * Suspend until a wall-clock instant, however far away.
     */
    public function sleepUntil(DateTimeInterface $until): void
    {
        $sequence = $this->nextSequence();
        $step = $this->step($sequence);

        if ($step instanceof RunStep) {
            $this->assertMatches($sequence, $step, new StepDescriptor(StepType::Timer, 'sleep'));

            if ($step->status === StepStatus::Completed) {
                return;
            }
        } else {
            $this->engine->recordSleep($this->run, $sequence, $until);
        }

        $this->waiting = true;

        $this->suspend();
    }

    /**
     * The version this run was started under, if any.
     *
     * Branch on this to keep runs that started before a change flowing through
     * the code they began with:
     *
     *   if ($this->version() === 'v1') { ... } else { ... }
     */
    public function version(): ?string
    {
        return $this->run->flow_version;
    }

    /**
     * A stable identifier for the saga group opening at this point.
     *
     * Derived from the replay cursor rather than a random value, so the same
     * group gets the same id on every drive.
     */
    public function sagaGroupId(): string
    {
        return 'saga-'.$this->cursor;
    }

    /**
     * Attach a queryable tag to the run.
     */
    public function tag(string $key, string $value): void
    {
        $this->tags[$key] = $value;
    }

    /**
     * @return array<string, string>
     */
    public function tags(): array
    {
        return $this->tags;
    }

    public function engine(): Engine
    {
        return $this->engine;
    }

    /**
     * Whether the replay stopped on a signal or timer rather than on work in
     * flight.
     */
    public function isWaiting(): bool
    {
        return $this->waiting;
    }

    /**
     * Stop the replay. Caught by the engine, never by flow code.
     */
    public function suspend(): never
    {
        throw Suspended::make();
    }

    /**
     * Guard the replay contract: the history must describe the same operation
     * the flow just asked for.
     */
    private function assertMatches(int $sequence, RunStep $step, StepDescriptor $descriptor): void
    {
        if ($step->type !== $descriptor->type || $step->name !== $descriptor->name) {
            throw HistoryMismatchException::at(
                $sequence,
                $step->type->value.':'.$step->name,
                $descriptor->type->value.':'.$descriptor->name,
            );
        }
    }

    private function errorMessage(RunStep $step): ?string
    {
        $error = $step->error;

        if ($error === null) {
            return null;
        }

        $message = $error['message'] ?? null;

        return is_string($message) ? $message : null;
    }
}
