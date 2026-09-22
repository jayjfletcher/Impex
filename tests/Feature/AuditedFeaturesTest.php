<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use JayI\Impex\Enums\RollbackFailure;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\RunTrigger;
use JayI\Impex\Enums\StepPhase;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Exceptions\DeadlineExceededException;
use JayI\Impex\Exceptions\FlowVersionMismatchException;
use JayI\Impex\Impex;
use JayI\Impex\Mcp\Tools\RunFlowTool;
use JayI\Impex\Models\Run;
use JayI\Impex\Runtime\Engine;
use JayI\Impex\Testing\Flows;
use JayI\Impex\Tests\Fixtures\AddOne;
use JayI\Impex\Tests\Fixtures\AlwaysFails;
use JayI\Impex\Tests\Fixtures\Calls;
use JayI\Impex\Tests\Fixtures\DeadlineFlow;
use JayI\Impex\Tests\Fixtures\LinearFlow;
use JayI\Impex\Tests\Fixtures\OptionalFlow;
use JayI\Impex\Tests\Fixtures\Rollback;
use JayI\Impex\Tests\Fixtures\SignalFlow;
use JayI\Impex\Tests\Fixtures\UnitFlow;
use JayI\Impex\Tests\Fixtures\VersionedFlow;

beforeEach(function (): void {
    Calls::reset();
    config()->set('queue.default', 'sync');
    config()->set('impex.flows', [
        'linear' => LinearFlow::class,
        'signal' => SignalFlow::class,
        'versioned' => VersionedFlow::class,
        'optional' => OptionalFlow::class,
        'unit' => UnitFlow::class,
        'deadline' => DeadlineFlow::class,
    ]);
});

// ---------------------------------------------------------------- deadlines

it('fails a step that passed its deadline, and unwinds the run', function (): void {
    $run = app(Impex::class)->run('deadline');

    // Put the run back into the state a killed invocation leaves behind: the
    // step claimed and running, its deadline already passed, nothing coming
    // back to complete it.
    $run->update(['status' => RunStatus::Running, 'finished_at' => null, 'result' => null]);

    $step = $run->refresh()->forwardSteps()->where('sequence', 1)->first();
    $step->update([
        'status' => StepStatus::Running,
        'completed_at' => null,
        'expires_at' => now()->subMinute(),
    ]);

    $this->artisan('impex:tick')->assertSuccessful();

    expect($step->refresh()->status)->toBe(StepStatus::Failed)
        ->and($step->error['class'])->toBe(DeadlineExceededException::class);

    // A deadline is an ordinary failure, so the rollback runs like any other.
    Flows::assertFailed($run);
    Flows::assertRolledBack($run->refresh(), Rollback::class);
});

it('fails a run that passed its own deadline', function (): void {
    $run = app(Impex::class)->run('signal', expiresAt: 60);

    Flows::assertWaiting($run);
    expect($run->refresh()->expires_at)->not->toBeNull();

    Flows::travelTo(now()->addMinutes(5));

    Flows::assertFailed($run, 'passed its deadline');
});

it('applies the configured default step deadline', function (): void {
    config()->set('impex.deadlines.step', 30);

    $run = app(Impex::class)->run('linear', [1]);

    expect($run->refresh()->forwardSteps()->first()->expires_at)->not->toBeNull();
});

it('leaves deadlines unset when none is configured', function (): void {
    $run = app(Impex::class)->run('linear', [1]);

    expect($run->refresh()->forwardSteps()->first()->expires_at)->toBeNull();
});

// ---------------------------------------------------------------- versioning

it('records the version a flow declares', function (): void {
    $run = app(Impex::class)->run('versioned');

    expect($run->refresh()->flow_version)->toBe('v2')
        ->and(app(Impex::class)->result($run))->toBe(['took' => 'current']);
});

it('lets a run started on an old version keep taking the old path', function (): void {
    // What a run in flight when v2 shipped looks like.
    $run = app(Impex::class)->run('versioned', version: 'v1');

    expect($run->refresh()->flow_version)->toBe('v1')
        ->and(app(Impex::class)->result($run))->toBe(['took' => 'legacy']);
});

it('fails a run whose slug was repointed at a different class', function (): void {
    $run = Run::query()->create([
        'flow' => 'linear',
        'flow_class' => VersionedFlow::class,   // what it started on
        'status' => RunStatus::Pending,
        'trigger' => RunTrigger::Code,
    ]);

    app(Engine::class)->drive((string) $run->getKey());

    Flows::assertFailed($run);

    expect($run->refresh()->error['class'])->toBe(FlowVersionMismatchException::class)
        ->and($run->error['message'])->toContain('Drain runs of a flow before repointing');
});

// ------------------------------------------------------------------ runSync

it('drives a run to completion in-process', function (): void {
    $run = app(Impex::class)->runSync('linear', [1]);

    // No worker involved: runSync executes steps inline.
    expect($run->status)->toBe(RunStatus::Completed)
        ->and(app(Impex::class)->result($run))->toBe(['value' => 4]);
});

it('returns a parked run rather than spinning until the budget expires', function (): void {
    $started = Carbon::now();

    $run = app(Impex::class)->runSync('signal', seconds: 30);

    // It gives up as soon as there is nothing left to push, not after 30s.
    expect($run->status)->toBe(RunStatus::Waiting)
        ->and(Carbon::now()->diffInSeconds($started))->toBeLessThan(5);
});

// ------------------------------------------------------------ optionalAction

it('carries on past an optional action that failed', function (): void {
    $run = app(Impex::class)->run('optional');

    Flows::assertCompleted($run);

    expect(app(Impex::class)->result($run->refresh()))->toBe(['result' => null])
        ->and(Calls::count('after-optional'))->toBe(1);

    Flows::assertNotRolledBack($run);
});

// ----------------------------------------------------------------- unit group

it('groups steps and rolls the whole group back', function (): void {
    $run = app(Impex::class)->run('unit');

    Flows::assertFailed($run);

    // Both rollbacks ran, newest first.
    expect(Calls::count('rollback:second'))->toBe(1)
        ->and(Calls::count('rollback:first'))->toBe(1);

    $group = $run->refresh()->forwardSteps()->whereNotNull('unit_id')->pluck('unit_id')->unique();

    expect($group)->toHaveCount(1);
});

it('rolls a parallel group back in one pass', function (): void {
    $run = app(Impex::class)->run('unit', [true]);

    Flows::assertFailed($run);

    // Both rollback steps were written together rather than one per drive.
    expect($run->refresh()->steps()->where('phase', StepPhase::Rollback)->count())->toBe(2)
        ->and(Calls::count('rollback:first'))->toBe(1)
        ->and(Calls::count('rollback:second'))->toBe(1);
});

it('halts the rollback when a rollback fails under the Halt policy', function (): void {
    $run = app(Impex::class)->run('unit');

    // Break the newest rollback and replay it: Stop is the default.
    $run->refresh()->steps()
        ->where('phase', StepPhase::Rollback)
        ->update(['status' => StepStatus::Failed]);

    $run->update(['status' => RunStatus::RollingBack, 'finished_at' => null]);

    app(Engine::class)->drive((string) $run->getKey());

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and($run->error['rollback'])->toContain('Unwind halted');
});

it('pushes through a failed rollback under the Continue policy', function (): void {
    $run = app(Impex::class)->run('unit', [false, RollbackFailure::Continue->value]);

    Flows::assertFailed($run);

    // Fail the first rollback that ran and let the engine carry on.
    $first = $run->refresh()->steps()
        ->where('phase', StepPhase::Rollback)
        ->orderBy('sequence')
        ->first();

    $first->update(['status' => StepStatus::Failed]);
    $run->update(['status' => RunStatus::RollingBack, 'finished_at' => null]);

    app(Engine::class)->drive((string) $run->getKey());

    // Skipped, not Failed: the engine recorded that it gave up on this one and
    // moved past it rather than looping on it forever.
    expect($first->refresh()->status)->toBe(StepStatus::Skipped);
});

// ------------------------------------------------------------------- queries

it('asks for runs in the shape the question is asked', function (): void {
    app(Impex::class)->run('linear', [1], tags: ['tenant' => 'acme']);
    app(Impex::class)->run('signal');

    $impex = app(Impex::class);

    expect($impex->query()->completed()->count())->toBe(1)
        ->and($impex->query()->waiting()->count())->toBe(1)
        ->and($impex->query()->whereFlow('linear')->whereTag('tenant', 'acme')->count())->toBe(1)
        ->and($impex->query()->signalable()->count())->toBe(1)
        // signalable() is not running(): the waiting run is the one you want.
        ->and($impex->query()->running()->count())->toBe(0);
});

it('hands back run handles you can act on', function (): void {
    app(Impex::class)->run('signal');

    $handle = app(Impex::class)->query()->signalable()->handles()->first();

    $handle->signal('approval', ['approved' => true]);

    expect($handle->refresh()->status)->toBe(RunStatus::Completed)
        ->and($handle->result())->toBe(['approval' => ['approved' => true]]);
});

// ------------------------------------------------------------- test helpers

it('ships helpers that assert what a flow did', function (): void {
    $run = Flows::run('linear', [1]);

    Flows::assertCompleted($run);
    Flows::assertStepRan($run, AddOne::class, 1);
    Flows::assertStepDidNotRun($run, AlwaysFails::class);
    Flows::assertForwardStepCount($run, 2);
    Flows::assertNotRolledBack($run);
});

it('proves redelivery does not repeat a side effect', function (): void {
    $run = Flows::run('linear', [1]);

    Flows::redeliverSteps($run);
    Flows::redeliverSteps($run);

    // Exactly what at-least-once delivery does, and the whole point of the
    // lease discipline.
    Flows::assertStepRan($run, AddOne::class, 1);
    expect(Calls::count('add-one'))->toBe(1);
});

it('exposes version, deadline and wait over the API', function (): void {
    $this->postJson('/impex/flows/versioned/runs', ['version' => 'v1', 'expires_in' => 600, 'wait' => true])
        ->assertStatus(202)
        ->assertJsonPath('data.version', 'v1')
        // wait drove it inline, so the caller sees the terminal state.
        ->assertJsonPath('data.status', 'completed');

    expect(Run::query()->firstOrFail()->expires_at)->not->toBeNull();
});

it('exposes the same over MCP', function (): void {
    mcpTool(RunFlowTool::class, [
        'flow' => 'versioned',
        'version' => 'v1',
        'wait' => true,
        // The resource carries the run, not the flow's return value — payloads
        // are never inlined in a listing.
    ])->assertOk()->assertSee('v1')->assertSee('completed');
});
