<?php

declare(strict_types=1);

namespace JayI\Impex\Runtime;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use JayI\Impex\Contracts\BatchSource;
use JayI\Impex\Enums\ArtifactKind;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Exceptions\BatchFailedException;
use JayI\Impex\Models\Batch;
use JayI\Impex\Models\BatchItem;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunStep;
use JayI\Impex\Support\Locks;
use JayI\Impex\Support\PayloadStore;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Seeds, processes, and finalises batches.
 *
 * A batch is one step in the replay history however many items it holds. Per
 * item state lives in `impex_batch_items`, which the replay never reads, so the
 * cost of a drive is independent of the item count — the property that makes a
 * million-item sweep possible at all.
 */
final class BatchRunner
{
    public function __construct(
        private readonly Container $container,
        private readonly PayloadStore $payloads,
        private readonly JobRouter $jobs,
        private readonly Locks $locks,
        private readonly EngineOptions $options,
    ) {}

    /**
     * Seed a page of work, resuming from the cursor and stopping before the
     * invocation's ceiling.
     */
    public function seed(string $batchId, ?string $cursor): void
    {
        $batch = Batch::query()->find($batchId);

        if (! $batch instanceof Batch || $batch->seeded) {
            return;
        }

        $source = $this->makeSource($batch);
        $deadline = StepDeadline::in($this->options->maxStepSeconds(), $this->options->resumeMargin());

        while (true) {
            $chunk = $source->chunk($cursor, $batch->chunk_size);

            $this->insert($batch, $chunk->items);

            if ($chunk->complete) {
                $batch->update(['seeded' => true]);

                $this->settle($batch->getKey());

                return;
            }

            if ($chunk->nextCursor === $cursor) {
                throw new RuntimeException(sprintf(
                    'The batch source [%s] returned the same cursor [%s] twice without completing, so it '.
                    'is not making progress.',
                    $batch->source,
                    $cursor ?? 'null',
                ));
            }

            $cursor = $chunk->nextCursor;

            // A Lambda timeout cannot be caught, so seeding stops short of the
            // ceiling and re-dispatches itself from the cursor it just stored.
            if ($deadline->reached()) {
                $this->jobs->seed($batch->run, $batchId, $cursor);

                return;
            }
        }
    }

    /**
     * Run one item's action, under the same lease discipline as a step.
     */
    public function processItem(string $itemId): void
    {
        $item = BatchItem::query()->find($itemId);

        if (! $item instanceof BatchItem || ! $item->status->isClaimable()) {
            return;
        }

        $batch = $item->batch;

        if (! $batch instanceof Batch) {
            return;
        }

        $token = (string) Str::ulid();

        $claimed = BatchItem::query()
            ->whereKey($item->getKey())
            ->whereIn('status', [
                StepStatus::Pending->value,
                StepStatus::Running->value,
                StepStatus::Failed->value,
            ])
            ->where(function (Builder $query): void {
                $query->whereNull('leased_until')->orWhere('leased_until', '<=', Carbon::now());
            })
            ->update([
                'status' => StepStatus::Running->value,
                'lease_token' => $token,
                'leased_until' => Carbon::now()->addSeconds($this->options->leaseSeconds()),
                'attempts' => $item->attempts + 1,
                'updated_at' => Carbon::now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        try {
            $action = $this->container->make($batch->action);

            if (! is_object($action) || ! method_exists($action, 'execute')) {
                throw new RuntimeException(sprintf(
                    'The Impex action [%s] must be a class declaring a public execute() method.',
                    $batch->action,
                ));
            }

            $result = $action->execute($this->payloads->get($item->payload, $item->payload_artifact_id));
        } catch (Throwable $e) {
            $this->failItem($batch, $item, $token, $e);

            return;
        }

        $stored = $this->payloads->put($result, ArtifactKind::Result, ['run_id' => $batch->run_id]);

        $written = BatchItem::query()
            ->whereKey($item->getKey())
            ->where('lease_token', $token)
            ->update([
                'status' => StepStatus::Completed->value,
                'result' => json_encode($stored['inline']),
                'result_artifact_id' => $stored['artifact_id'],
                'lease_token' => null,
                'leased_until' => null,
                'updated_at' => Carbon::now(),
            ]);

        if ($written === 0) {
            return;
        }

        Batch::query()->whereKey($batch->getKey())->increment('succeeded');

        $this->settle((string) $batch->getKey());
    }

    /**
     * Finish the batch if every item has settled.
     *
     * Throttled by a short cache lock so a thousand items completing at once do
     * not each run the completion query; `impex:tick` sweeps any batch this
     * misses, so a skipped check only delays finalisation.
     */
    public function settle(string $batchId, bool $force = false): void
    {
        $lock = $this->locks->acquire('impex:batch:'.$batchId, 30);

        if (! $force && ! $lock->get()) {
            return;
        }

        try {
            $this->finalize($batchId);
        } finally {
            if (! $force) {
                $lock->release();
            }
        }
    }

    /**
     * Sweep batches whose completion check was throttled away.
     *
     * @return int the number finalised
     */
    public function sweep(int $limit = 100): int
    {
        $batches = Batch::query()
            ->where('seeded', true)
            ->whereNull('finalized_at')
            ->limit($limit)
            ->pluck('id');

        $finalized = 0;

        foreach ($batches as $batchId) {
            if ($this->finalize((string) $batchId)) {
                $finalized++;
            }
        }

        return $finalized;
    }

    private function finalize(string $batchId): bool
    {
        $batch = Batch::query()->find($batchId);

        if (! $batch instanceof Batch || ! $batch->seeded || $batch->finalized_at !== null) {
            return false;
        }

        $outstanding = BatchItem::query()
            ->where('batch_id', $batch->getKey())
            ->whereIn('status', [StepStatus::Pending, StepStatus::Running])
            ->exists();

        if ($outstanding) {
            return false;
        }

        $batch->update(['finalized_at' => Carbon::now()]);
        $batch->refresh();

        $step = RunStep::query()->find($batch->step_id);
        $run = Run::query()->find($batch->run_id);

        if (! $step instanceof RunStep || ! $run instanceof Run) {
            return false;
        }

        $summary = [
            'batch_id' => (string) $batch->getKey(),
            'total' => $batch->total,
            'succeeded' => $batch->succeeded,
            'failed' => $batch->failed,
        ];

        if ($batch->withinFailureThreshold()) {
            $stored = $this->payloads->put($summary, ArtifactKind::Result, ['run_id' => $batch->run_id]);

            $step->update([
                'status' => StepStatus::Completed,
                'result' => $stored['inline'],
                'result_artifact_id' => $stored['artifact_id'],
                'completed_at' => Carbon::now(),
            ]);
        } else {
            $error = BatchFailedException::threshold(
                $batch->action,
                $batch->failed,
                $batch->total,
                $batch->allow_failures,
            );

            $step->update([
                'status' => StepStatus::Failed,
                'error' => [
                    'class' => $error::class,
                    'message' => $error->getMessage(),
                    'summary' => $summary,
                ],
                'completed_at' => Carbon::now(),
            ]);
        }

        $this->container->make(Engine::class)->dispatchDrive($run);

        return true;
    }

    /**
     * @param  array<int, BatchChunkItem>  $items
     */
    private function insert(Batch $batch, array $items): void
    {
        if ($items === []) {
            return;
        }

        $inserted = 0;

        foreach ($items as $item) {
            $stored = $this->payloads->put($item->payload, ArtifactKind::Payload, [
                'run_id' => $batch->run_id,
            ]);

            // insertOrIgnore against unique(batch_id, item_key): a redelivered
            // seed writes nothing and dispatches nothing.
            $created = BatchItem::query()->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'batch_id' => $batch->getKey(),
                'item_key' => $item->key,
                'payload' => json_encode($stored['inline']),
                'payload_artifact_id' => $stored['artifact_id'],
                'status' => StepStatus::Pending->value,
                'attempts' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            if ($created === 0) {
                continue;
            }

            $inserted++;

            $row = BatchItem::query()
                ->where('batch_id', $batch->getKey())
                ->where('item_key', $item->key)
                ->first();

            if ($row instanceof BatchItem) {
                $this->jobs->batchItem($batch->run, (string) $row->getKey());
            }
        }

        if ($inserted > 0) {
            Batch::query()->whereKey($batch->getKey())->increment('total', $inserted);
        }
    }

    private function failItem(Batch $batch, BatchItem $item, string $token, Throwable $error): void
    {
        $describe = [
            'class' => $error::class,
            'message' => $error->getMessage(),
        ];

        if ($item->attempts + 1 < $batch->max_attempts) {
            BatchItem::query()
                ->whereKey($item->getKey())
                ->where('lease_token', $token)
                ->update([
                    'status' => StepStatus::Pending->value,
                    'lease_token' => null,
                    'leased_until' => null,
                    'error' => json_encode($describe),
                    'updated_at' => Carbon::now(),
                ]);

            $this->jobs->batchItem($batch->run, (string) $item->getKey());

            return;
        }

        $written = BatchItem::query()
            ->whereKey($item->getKey())
            ->where('lease_token', $token)
            ->update([
                'status' => StepStatus::Failed->value,
                'lease_token' => null,
                'leased_until' => null,
                'error' => json_encode($describe),
                'updated_at' => Carbon::now(),
            ]);

        if ($written === 0) {
            return;
        }

        Batch::query()->whereKey($batch->getKey())->increment('failed');

        $this->settle((string) $batch->getKey());
    }

    private function makeSource(Batch $batch): BatchSource
    {
        /** @var array<int, mixed> $arguments */
        $arguments = (array) $this->payloads->get($batch->source_arguments, null);

        $source = $this->container->make(
            $batch->source,
            $this->nameArguments($batch->source, array_values($arguments)),
        );

        if (! $source instanceof BatchSource) {
            throw new RuntimeException(sprintf(
                'The batch source [%s] must implement %s.',
                $batch->source,
                BatchSource::class,
            ));
        }

        return $source;
    }

    /**
     * Key positional arguments by the constructor parameter they fill.
     *
     * The container matches extra make() arguments by parameter NAME, so a
     * positional list is silently ignored and the source is built entirely from
     * its defaults. Recovering the names keeps
     * `batch(Source::class, $a, $b)` working the way
     * `action(Action::class, $a, $b)` does, where the arguments are spread into
     * a method call and position is all that matters.
     *
     * @param  array<int, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function nameArguments(string $source, array $arguments): array
    {
        // The class name comes off a database column, so it is not known to
        // name anything real until it is checked. A miss falls through to
        // make(), which raises the binding error the caller needs to see.
        if ($arguments === [] || ! class_exists($source)) {
            return [];
        }

        $constructor = (new ReflectionClass($source))->getConstructor();

        if (! $constructor instanceof ReflectionMethod) {
            return [];
        }

        $named = [];

        foreach ($constructor->getParameters() as $position => $parameter) {
            if (! array_key_exists($position, $arguments)) {
                break;
            }

            // A variadic tail takes every remaining argument, and the container
            // cannot fill one by name — leave those to the source's defaults
            // rather than binding the list to the wrong parameter.
            if ($parameter->isVariadic()) {
                break;
            }

            $named[$parameter->getName()] = $arguments[$position];
        }

        return $named;
    }
}
