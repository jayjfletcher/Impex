<?php

declare(strict_types=1);

namespace JayI\Impex\Flows\Builders;

use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Enums\StepType;
use JayI\Impex\Exceptions\HistoryMismatchException;
use JayI\Impex\Exceptions\StepFailedException;
use JayI\Impex\Models\RunStep;
use JayI\Impex\Runtime\Context;

/**
 * Runs one action over an unbounded stream of items.
 *
 * The whole batch is ONE step in the replay history whatever its item count,
 * because per-item state lives in `impex_batch_items` — a table the replay
 * never reads. That is what keeps the cost of a drive independent of the item
 * count, and it is the reason a million-item sweep is possible at all.
 *
 * The tradeoff against fanOut(): batched items get no per-item compensation, no
 * positional results, and no per-item signals. Use fanOut when one item failing
 * should unwind the run item-by-item; use batch for throughput.
 */
final class BatchBuilder
{
    private string $action = '';

    private int $chunkSize = 500;

    private float $allowFailures = 0.0;

    private int $maxAttempts = 1;

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __construct(
        private readonly Context $context,
        private readonly string $source,
        private readonly array $arguments,
    ) {}

    /**
     * The action run once per item.
     */
    public function using(string $action): self
    {
        $this->action = $action;

        return $this;
    }

    /**
     * How many items the source is asked for at a time.
     */
    public function chunk(int $size): self
    {
        $this->chunkSize = max(1, $size);

        return $this;
    }

    /**
     * The share of items that may fail before the batch fails the run.
     *
     * Expressed as a fraction: 0.02 tolerates 2%.
     */
    public function allowFailures(float $share): self
    {
        $this->allowFailures = max(0.0, min(1.0, $share));

        return $this;
    }

    /**
     * How many times an individual item may be attempted.
     */
    public function tries(int $attempts): self
    {
        $this->maxAttempts = max(1, $attempts);

        return $this;
    }

    /**
     * Resolve the batch: return its summary, or start it and suspend.
     *
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $sequence = $this->context->nextSequence();
        $step = $this->context->step($sequence);

        if (! $step instanceof RunStep) {
            $this->context->engine()->startBatch(
                $this->context->run,
                $sequence,
                $this->source,
                $this->arguments,
                $this->action,
                $this->chunkSize,
                $this->allowFailures,
                $this->maxAttempts,
            );

            $this->context->suspend();
        }

        if ($step->type !== StepType::Batch || $step->name !== $this->source) {
            throw HistoryMismatchException::at(
                $sequence,
                $step->type->value.':'.$step->name,
                StepType::Batch->value.':'.$this->source,
            );
        }

        return match ($step->status) {
            StepStatus::Completed => (array) $this->context->engine()->payloads()
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
