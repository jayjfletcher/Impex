<?php

declare(strict_types=1);

namespace JayI\Impex\Flows;

use Closure;
use DateTimeInterface;
use JayI\Impex\Flows\Builders\ActionBuilder;
use JayI\Impex\Flows\Builders\BatchBuilder;
use JayI\Impex\Flows\Builders\FanOutBuilder;
use JayI\Impex\Flows\Builders\ParallelBuilder;
use JayI\Impex\Flows\Builders\SignalBuilder;
use JayI\Impex\Runtime\Context;
use RuntimeException;

/**
 * Base class for a workflow.
 *
 * Subclasses implement a public `handle()` method with whatever signature suits
 * them. That method is re-executed from the top on every resume, so it must be
 * deterministic: every call into the DSL below is keyed by its position in the
 * replay, and anything that cannot be recomputed — a clock read, a random
 * value, an unrecorded query — must be wrapped in sideEffect().
 */
abstract class Flow
{
    private ?Context $impexContext = null;

    /**
     * Bind the replay context. Called by the engine, not by flow code.
     */
    final public function withContext(Context $context): static
    {
        $this->impexContext = $context;

        return $this;
    }

    /**
     * The active replay context.
     */
    final public function context(): Context
    {
        if (! $this->impexContext instanceof Context) {
            throw new RuntimeException(sprintf(
                'The flow [%s] has no replay context. Start flows through Impex::run() rather than calling handle() directly.',
                static::class,
            ));
        }

        return $this->impexContext;
    }

    /**
     * Run a unit of work as a recorded, retryable, compensatable step.
     */
    final protected function action(string $action, mixed ...$arguments): ActionBuilder
    {
        return new ActionBuilder($this->context(), $action, array_values($arguments));
    }

    /**
     * Run several actions concurrently, joining on all of them.
     */
    final protected function parallel(): ParallelBuilder
    {
        return new ParallelBuilder($this->context());
    }

    /**
     * Run one action per item in a collection.
     *
     * Each item becomes its own recorded step, so items retry and compensate
     * individually. Replay is O(history) per drive, so this is capped at
     * `impex.limits.fan_out_max` — use batch() above it.
     *
     * @param  iterable<int|string, mixed>  $items
     * @param  Closure(mixed, int|string): ActionBuilder  $using
     */
    final protected function fanOut(iterable $items, Closure $using): FanOutBuilder
    {
        return new FanOutBuilder($this->context(), $items, $using);
    }

    /**
     * Run one action over an unbounded stream of items.
     *
     * The batch is a single step in the replay history whatever its size, so
     * the cost of a drive does not grow with the item count.
     */
    final protected function batch(string $source, mixed ...$arguments): BatchBuilder
    {
        return new BatchBuilder($this->context(), $source, array_values($arguments));
    }

    /**
     * Record a value that cannot be recomputed deterministically.
     *
     * The callback runs once; every later replay reuses the recorded value.
     */
    final protected function sideEffect(string $key, Closure $callback): mixed
    {
        return $this->context()->sideEffect($key, $callback);
    }

    /**
     * Wait for an externally delivered signal, with options.
     *
     *   $decision = $this->signal('approval')
     *       ->timeoutAfter(now()->addDay())
     *       ->default(['approved' => false])
     *       ->wait();
     */
    final protected function signal(string $name): SignalBuilder
    {
        return new SignalBuilder($this->context(), $name);
    }

    /**
     * Suspend until a signal of this name is delivered to the run.
     *
     * The shorthand for `signal($name)->wait()`. A timed out wait returns null
     * so the flow can branch on it; use `signal($name)->orFail()` when a
     * missing signal should unwind the run instead.
     */
    final protected function awaitSignal(string $name, ?DateTimeInterface $timeout = null): mixed
    {
        return $this->context()->awaitSignal($name, $timeout);
    }

    /**
     * Suspend until a wall-clock instant, however far away.
     *
     * Waits beyond the queue's delay ceiling become timer rows swept by
     * `impex:tick`, so a multi-day wait costs nothing while it is pending.
     */
    final protected function sleepUntil(DateTimeInterface $until): void
    {
        $this->context()->sleepUntil($until);
    }

    /**
     * Attach a queryable tag to the run.
     */
    final protected function tag(string $key, string $value): void
    {
        $this->context()->tag($key, $value);
    }
}
