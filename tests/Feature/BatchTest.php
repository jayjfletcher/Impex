<?php

declare(strict_types=1);

use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Enums\StepType;
use JayI\Impex\Impex;
use JayI\Impex\Models\Batch;
use JayI\Impex\Models\BatchItem;
use JayI\Impex\Runtime\BatchRunner;
use JayI\Impex\Tests\Fixtures\BatchFlow;
use JayI\Impex\Tests\Fixtures\Calls;

beforeEach(function (): void {
    Calls::reset();
    config()->set('queue.default', 'sync');
    config()->set('impex.flows', ['batching' => BatchFlow::class]);
});

it('passes constructor arguments to the batch source', function (): void {
    // PagedSource defaults to 3 pages of 2, so only a non-default value proves
    // the argument arrived: the container matches extra make() arguments by
    // parameter name, and a positional list used to be dropped silently,
    // leaving every source built from its defaults.
    $run = app(Impex::class)->run('batching', [5]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and(BatchItem::query()->count())->toBe(10);
});

it('keeps the replay history at one step whatever the item count', function (): void {
    $run = app(Impex::class)->run('batching', [3]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed);

    // Six items processed, and the run's history is a single step. This is the
    // whole point of batch(): drive cost is independent of item count.
    expect(BatchItem::query()->count())->toBe(6)
        ->and($run->forwardSteps()->count())->toBe(1);

    $step = $run->forwardSteps()->first();

    expect($step->type)->toBe(StepType::Batch)
        ->and($step->status)->toBe(StepStatus::Completed);
});

it('returns an aggregate summary rather than positional results', function (): void {
    $run = app(Impex::class)->run('batching', [3]);

    $summary = app(Impex::class)->result($run->refresh());

    expect($summary['total'])->toBe(6)
        ->and($summary['succeeded'])->toBe(5)
        ->and($summary['failed'])->toBe(1)
        ->and($summary['batch_id'])->toBeString()
        ->and(Calls::count('after-batch'))->toBe(1);
});

it('seeds across multiple invocations, resuming from the source cursor', function (): void {
    app(Impex::class)->run('batching', [3]);

    // Three pages plus the terminal chunk: the source was asked for work four
    // times, each a separate resumable step.
    expect(Calls::count('source-chunk'))->toBe(4);
});

it('gives each item an idempotency key so a redelivered seed is a no-op', function (): void {
    $run = app(Impex::class)->run('batching', [3]);

    $batch = Batch::query()->where('run_id', $run->getKey())->firstOrFail();

    expect(Calls::count('enrich'))->toBe(6);

    // Replay the seed from the start. unique(batch_id, item_key) means every
    // insert loses and nothing is dispatched a second time.
    app(BatchRunner::class)->seed((string) $batch->getKey(), null);

    expect(BatchItem::query()->count())->toBe(6)
        ->and(Calls::count('enrich'))->toBe(6);
});

it('fails the run when failures exceed the tolerated share', function (): void {
    // One of six items always fails — about 17%, above a 10% tolerance.
    $run = app(Impex::class)->run('batching', [3, 0.1]);

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and($run->error['message'])->toContain('above the tolerated')
        ->and(Calls::count('after-batch'))->toBe(0);
});

it('records per-item failures without stopping the other items', function (): void {
    app(Impex::class)->run('batching', [3]);

    $failed = BatchItem::query()->where('status', StepStatus::Failed)->get();

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->item_key)->toBe('P1-1')
        ->and($failed[0]->error['message'])->toBe('this one always fails')
        ->and(BatchItem::query()->where('status', StepStatus::Completed)->count())->toBe(5);
});
