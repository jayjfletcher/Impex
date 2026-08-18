<?php

declare(strict_types=1);

namespace JayI\Impex\Flows\Builders;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use JayI\Impex\Runtime\Context;

/**
 * Waits for an externally delivered signal.
 *
 * A run parked here costs nothing: it holds no worker, no connection, and no
 * queue message. A signal delivered before the flow reaches the wait is held
 * and consumed on arrival, so there is no race between the run's progress and
 * the speed of whatever is signalling it.
 */
final class SignalBuilder
{
    private ?DateTimeInterface $timeout = null;

    private bool $failOnTimeout = false;

    private mixed $default = null;

    public function __construct(
        private readonly Context $context,
        private readonly string $name,
    ) {}

    /**
     * Give up waiting at this instant.
     *
     * Accepts a moment, or a number of seconds from now. However far away, the
     * wait becomes a timer row rather than a delayed job — SQS caps message
     * delay at 15 minutes.
     */
    public function timeoutAfter(DateTimeInterface|int $when): self
    {
        $this->timeout = is_int($when) ? Carbon::now()->addSeconds($when) : $when;

        return $this;
    }

    /**
     * Throw SignalTimeoutException when the deadline passes.
     *
     * Without this a timed out wait returns the default, letting the flow
     * branch rather than unwinding. Use `orFail()` when a missing signal really
     * is a failure worth rolling back for.
     */
    public function orFail(): self
    {
        $this->failOnTimeout = true;

        return $this;
    }

    /**
     * The value a timed out wait returns. Defaults to null.
     */
    public function default(mixed $value): self
    {
        $this->default = $value;

        return $this;
    }

    /**
     * Suspend until the signal arrives, or the deadline passes.
     */
    public function wait(): mixed
    {
        return $this->context->awaitSignal(
            $this->name,
            $this->timeout,
            $this->failOnTimeout,
            $this->default,
        );
    }
}
