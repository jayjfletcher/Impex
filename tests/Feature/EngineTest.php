<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\StepPhase;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Enums\StepType;
use JayI\Impex\Impex;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunStep;
use JayI\Impex\Runtime\Engine;
use JayI\Impex\Tests\Fixtures\AddOne;
use JayI\Impex\Tests\Fixtures\Calls;
use JayI\Impex\Tests\Fixtures\CompensatingFlow;
use JayI\Impex\Tests\Fixtures\LargePayloadFlow;
use JayI\Impex\Tests\Fixtures\LinearFlow;
use JayI\Impex\Tests\Fixtures\ParallelFlow;
use JayI\Impex\Tests\Fixtures\ResumingFlow;
use JayI\Impex\Tests\Fixtures\SideEffectFlow;
use JayI\Impex\Tests\Fixtures\SignalFlow;

beforeEach(function (): void {
    Calls::reset();

    // The sync driver makes the whole run deterministic: a drive schedules a
    // step, the step runs inline, and its completion re-enters the drive loop.
    config()->set('queue.default', 'sync');

    config()->set('impex.flows', [
        'linear' => LinearFlow::class,
        'parallel' => ParallelFlow::class,
        'compensating' => CompensatingFlow::class,
        'side-effect' => SideEffectFlow::class,
        'signal' => SignalFlow::class,
        'large' => LargePayloadFlow::class,
        'resuming' => ResumingFlow::class,
    ]);
});

it('runs a linear flow to completion', function (): void {
    $run = app(Impex::class)->run('linear', [1]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and(app(Impex::class)->result($run))->toBe(['value' => 4])
        ->and(Calls::count('add-one'))->toBe(1)
        ->and(Calls::count('double'))->toBe(1);
});

it('records every operation in replay order', function (): void {
    $run = app(Impex::class)->run('linear', [1]);

    $steps = $run->forwardSteps()->get();

    expect($steps)->toHaveCount(2)
        ->and($steps[0]->sequence)->toBe(0)
        ->and($steps[0]->type)->toBe(StepType::Action)
        ->and($steps[0]->status)->toBe(StepStatus::Completed)
        ->and($steps[1]->sequence)->toBe(1);
});

it('never re-runs a completed step when the run is driven again', function (): void {
    $run = app(Impex::class)->run('linear', [1]);

    expect(Calls::count('add-one'))->toBe(1);

    // Redeliver the drive. A completed run is a no-op; the point is that the
    // replay reuses recorded results rather than calling the action again.
    app(Engine::class)->drive((string) $run->getKey());
    app(Engine::class)->drive((string) $run->getKey());

    expect(Calls::count('add-one'))->toBe(1)
        ->and(Calls::count('double'))->toBe(1);
});

it('is idempotent when a step job is redelivered', function (): void {
    $run = app(Impex::class)->run('linear', [1]);

    // Exactly what SQS at-least-once delivery does.
    app(Engine::class)->executeStep((string) $run->getKey(), StepPhase::Forward->value, 0);
    app(Engine::class)->executeStep((string) $run->getKey(), StepPhase::Forward->value, 0);
    app(Engine::class)->executeStep((string) $run->getKey(), StepPhase::Forward->value, 1);

    expect(Calls::count('add-one'))->toBe(1)
        ->and(Calls::count('double'))->toBe(1);
});

it('will not execute a step whose lease is held by another invocation', function (): void {
    $run = Run::factory()->create([
        'flow' => 'linear',
        'flow_class' => LinearFlow::class,
        'status' => RunStatus::Running,
    ]);

    RunStep::factory()->create([
        'run_id' => $run->getKey(),
        'sequence' => 0,
        'name' => AddOne::class,
        'input' => ['value' => [1]],
        'status' => StepStatus::Running,
        'lease_token' => (string) Str::ulid(),
        'leased_until' => now()->addMinutes(10),
    ]);

    app(Engine::class)->executeStep((string) $run->getKey(), StepPhase::Forward->value, 0);

    expect(Calls::count('add-one'))->toBe(0);
});

it('reclaims a step whose lease has lapsed', function (): void {
    $run = Run::factory()->create([
        'flow' => 'linear',
        'flow_class' => LinearFlow::class,
        'status' => RunStatus::Running,
    ]);

    RunStep::factory()->create([
        'run_id' => $run->getKey(),
        'sequence' => 0,
        'name' => AddOne::class,
        'input' => ['value' => [1]],
        'status' => StepStatus::Running,
        'lease_token' => (string) Str::ulid(),
        'leased_until' => now()->subMinute(),
    ]);

    app(Engine::class)->executeStep((string) $run->getKey(), StepPhase::Forward->value, 0);

    expect(Calls::count('add-one'))->toBe(1);
});

it('schedules every branch of a parallel block in one drive', function (): void {
    $run = app(Impex::class)->run('parallel', [3]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and(app(Impex::class)->result($run))->toBe(['sum' => 10])
        ->and($run->forwardSteps()->count())->toBe(2);
});

it('compensates completed steps in reverse when a step fails', function (): void {
    $run = app(Impex::class)->run('compensating');

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and(Calls::count('rollback:add-one'))->toBe(1);

    $compensation = $run->steps()->where('phase', StepPhase::Compensation)->get();

    expect($compensation)->toHaveCount(1)
        ->and($compensation[0]->status)->toBe(StepStatus::Completed)
        ->and($compensation[0]->compensates_sequence)->toBe(0);

    expect($run->forwardSteps()->where('sequence', 0)->first()->compensated)->toBeTrue();
});

it('records a side effect once and reuses it on replay', function (): void {
    $run = app(Impex::class)->run('side-effect');

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and(Calls::count('side-effect'))->toBe(1);

    $step = $run->forwardSteps()->where('sequence', 0)->first();

    expect($step->type)->toBe(StepType::SideEffect)
        ->and($step->name)->toBe('stamp');
});

it('waits for a signal and resumes when one is delivered', function (): void {
    $impex = app(Impex::class);
    $run = $impex->run('signal');

    expect($run->refresh()->status)->toBe(RunStatus::Waiting);

    $impex->signal($run, 'approval', ['approved' => true]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and($impex->result($run))->toBe(['approval' => ['approved' => true]]);
});

it('offloads an oversized payload to an artifact instead of the database', function (): void {
    Storage::fake('local');

    $run = app(Impex::class)->run('large');

    expect($run->refresh()->status)->toBe(RunStatus::Completed);

    $step = $run->forwardSteps()->where('sequence', 0)->first();

    expect($step->result)->toBeNull()
        ->and($step->result_artifact_id)->not->toBeNull()
        ->and(app(Impex::class)->result($run))->toBe(['length' => 200_000]);
});

it('resumes a step from its cursor without adding steps to the history', function (): void {
    $run = app(Impex::class)->run('resuming', [4]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and(app(Impex::class)->result($run))->toBe(['pages' => 4])
        // Four invocations of one action, and still one row in the history:
        // the replay cannot tell a resumed step from a slow one.
        ->and(Calls::count('seed'))->toBe(4)
        ->and($run->forwardSteps()->count())->toBe(1);

    $step = $run->forwardSteps()->first();

    expect($step->resumptions)->toBe(3)
        ->and($step->status)->toBe(StepStatus::Completed);
});

it('returns the original run when an idempotency key is reused', function (): void {
    $impex = app(Impex::class);

    $first = $impex->run('linear', [1], idempotencyKey: 'order-42');
    $second = $impex->run('linear', [1], idempotencyKey: 'order-42');

    expect($second->getKey())->toBe($first->getKey())
        ->and(Calls::count('add-one'))->toBe(1);
});
