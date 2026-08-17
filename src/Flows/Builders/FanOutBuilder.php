<?php

declare(strict_types=1);

namespace JayI\Impex\Flows\Builders;

use Closure;
use JayI\Impex\Enums\ParallelFailure;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Enums\StepType;
use JayI\Impex\Exceptions\FanOutTooLargeException;
use JayI\Impex\Exceptions\HistoryMismatchException;
use JayI\Impex\Exceptions\StepFailedException;
use JayI\Impex\Models\RunStep;
use JayI\Impex\Runtime\Context;

/**
 * Runs one action per item in a collection.
 *
 * Sequences are positional, so a fan-out is only safe while the collection it
 * walks is stable. In practice it usually is — the collection is the recorded
 * result of a previous step, replayed byte-identically. Where it is not, this
 * builder catches the problem rather than silently handing item B's result to
 * item A's continuation:
 *
 * - a marker step records a fingerprint of the collection, and a replay whose
 *   collection no longer matches fails immediately, naming the fan-out
 * - `keyBy()` makes the fan-out tolerant of re-ordering, by resolving items
 *   against the recorded keys instead of their position
 *
 * Replay is O(history) per drive, so this is capped at `impex.limits.fan_out_max`.
 * Above it, use batch().
 */
final class FanOutBuilder
{
    private ?Closure $keyBy = null;

    private ParallelFailure $failurePolicy = ParallelFailure::FailFast;

    /**
     * @param  iterable<int|string, mixed>  $items
     * @param  Closure(mixed, int|string): ActionBuilder  $using
     */
    public function __construct(
        private readonly Context $context,
        private readonly iterable $items,
        private readonly Closure $using,
    ) {}

    /**
     * Identify each item by a stable key rather than its position.
     *
     * Use this when the collection's order is not guaranteed between drives.
     *
     * @param  Closure(mixed, int|string): string  $keyBy
     */
    public function keyBy(Closure $keyBy): self
    {
        $this->keyBy = $keyBy;

        return $this;
    }

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
     * Resolve the fan-out, returning one result per item in key order.
     *
     * @return array<int, mixed>
     */
    public function run(): array
    {
        $items = $this->normalize();
        $keys = array_keys($items);

        $this->assertWithinCap(count($items));

        // The marker holds the fingerprint and the key order, so a divergent
        // collection is caught before any branch is misread.
        $markerSequence = $this->context->nextSequence();
        $fingerprint = $this->fingerprint($items, $keys);
        $recorded = $this->resolveMarker($markerSequence, $fingerprint, $keys);

        // Replay against the recorded key order: with keyBy this absorbs a
        // re-ordered collection; without it the fingerprint has already
        // guaranteed the order is unchanged.
        $ordered = [];

        foreach ($recorded as $key) {
            if (! array_key_exists($key, $items)) {
                throw HistoryMismatchException::fanOutItem($markerSequence, (string) $key);
            }

            $ordered[$key] = $items[$key];
        }

        return $this->resolveBranches($ordered);
    }

    /**
     * Materialise the collection, keyed by the caller's key or by position.
     *
     * @return array<int|string, mixed>
     */
    private function normalize(): array
    {
        $items = [];
        $position = 0;

        foreach ($this->items as $item) {
            $key = $this->keyBy instanceof Closure
                ? ($this->keyBy)($item, $position)
                : $position;

            $items[$key] = $item;
            $position++;
        }

        if ($this->keyBy instanceof Closure) {
            // A stable order for a keyed fan-out, so re-ordering upstream does
            // not move any item's sequence.
            ksort($items);
        }

        return $items;
    }

    private function assertWithinCap(int $count): void
    {
        /** @var int $cap */
        $cap = config('impex.limits.fan_out_max', 100);

        if ($count > $cap) {
            throw FanOutTooLargeException::for($count, $cap);
        }
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @param  array<int, int|string>  $keys
     */
    private function fingerprint(array $items, array $keys): string
    {
        // With a key, the identity of the fan-out is its key set — order and
        // item contents may drift. Without one, position is the identity, so
        // the whole collection is hashed.
        $subject = $this->keyBy instanceof Closure ? $keys : $items;

        return hash('sha256', (string) json_encode($subject));
    }

    /**
     * @param  array<int, int|string>  $keys
     * @return array<int, int|string>
     */
    private function resolveMarker(int $sequence, string $fingerprint, array $keys): array
    {
        $step = $this->context->step($sequence);

        if (! $step instanceof RunStep) {
            $this->context->engine()->recordFanOut($this->context->run, $sequence, $fingerprint, $keys);

            return $keys;
        }

        if ($step->type !== StepType::FanOut) {
            throw HistoryMismatchException::at(
                $sequence,
                $step->type->value.':'.$step->name,
                StepType::FanOut->value.':fan-out',
            );
        }

        /** @var array<string, mixed> $result */
        $result = (array) $this->context->engine()->payloads()->get($step->result, $step->result_artifact_id);

        $previous = is_string($result['fingerprint'] ?? null) ? $result['fingerprint'] : '';

        if ($previous !== $fingerprint) {
            throw HistoryMismatchException::fanOut(
                $sequence,
                is_array($result['keys'] ?? null) ? count($result['keys']) : 0,
                count($keys),
            );
        }

        /** @var array<int, int|string> $recorded */
        $recorded = is_array($result['keys'] ?? null) ? array_values($result['keys']) : $keys;

        return $recorded;
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @return array<int, mixed>
     */
    private function resolveBranches(array $items): array
    {
        $descriptors = [];
        $sequences = [];

        foreach ($items as $key => $item) {
            $descriptors[$key] = ($this->using)($item, $key)->descriptor();
            $sequences[$key] = $this->context->nextSequence();
        }

        $results = [];
        $failures = [];
        $pending = false;

        foreach ($descriptors as $key => $descriptor) {
            $step = $this->context->step($sequences[$key]);

            if (! $step instanceof RunStep) {
                $this->context->engine()->scheduleStep($this->context->run, $sequences[$key], $descriptor);
                $pending = true;

                continue;
            }

            match ($step->status) {
                StepStatus::Completed => $results[$key] = $this->context->engine()->payloads()
                    ->get($step->result, $step->result_artifact_id),
                StepStatus::Skipped => $results[$key] = $descriptor->fallback,
                StepStatus::Failed => $descriptor->continueOnFailure
                    ? $results[$key] = $descriptor->fallback
                    : $failures[$key] = $step,
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

        return array_values($results);
    }

    /**
     * @param  array<int|string, RunStep>  $failures
     * @param  array<int|string, int>  $sequences
     */
    private function throwFirst(array $failures, array $sequences): never
    {
        $key = array_key_first($failures);
        $step = $failures[$key];

        $error = $step->error;
        $message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : null;

        throw StepFailedException::for($sequences[$key], $step->name, $message);
    }
}
