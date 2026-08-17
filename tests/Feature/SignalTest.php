<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\RunTrigger;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Enums\StepType;
use JayI\Impex\Exceptions\CannotSignalTerminalRunException;
use JayI\Impex\Exceptions\SignalTimeoutException;
use JayI\Impex\Impex;
use JayI\Impex\Mcp\ImpexServer;
use JayI\Impex\Mcp\Tools\SignalRunTool;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\Signal;
use JayI\Impex\Runtime\Engine;
use JayI\Impex\Tests\Fixtures\Calls;
use JayI\Impex\Tests\Fixtures\LinearFlow;
use JayI\Impex\Tests\Fixtures\NullSignalFlow;
use JayI\Impex\Tests\Fixtures\SignalFlow;
use JayI\Impex\Tests\Fixtures\StrictSignalFlow;
use JayI\Impex\Tests\Fixtures\TimeoutSignalFlow;

beforeEach(function (): void {
    Calls::reset();
    config()->set('queue.default', 'sync');
    config()->set('impex.flows', [
        'signal' => SignalFlow::class,
        'timeout' => TimeoutSignalFlow::class,
        'strict' => StrictSignalFlow::class,
        'null-signal' => NullSignalFlow::class,
        'linear' => LinearFlow::class,
    ]);
});

it('parks a run until the signal arrives', function (): void {
    $run = app(Impex::class)->run('signal');

    expect($run->refresh()->status)->toBe(RunStatus::Waiting);

    app(Impex::class)->signal($run, 'approval', ['approved' => true]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and(app(Impex::class)->result($run))->toBe(['approval' => ['approved' => true]]);
});

it('holds a signal delivered before the run reaches its wait', function (): void {
    $run = Run::query()->create([
        'flow' => 'signal',
        'flow_class' => SignalFlow::class,
        'status' => RunStatus::Pending,
        'trigger' => RunTrigger::Code,
    ]);

    // Delivered while the run is still pending — before the flow has ever run.
    app(Impex::class)->signal($run, 'approval', ['approved' => true]);

    app(Engine::class)->drive((string) $run->getKey());

    // Consumed inline, so the run never parks at all.
    expect($run->refresh()->status)->toBe(RunStatus::Completed);
});

it('refuses to signal a run that has finished', function (): void {
    $run = app(Impex::class)->run('linear', [1]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed);

    // Accepting it would leave a row nothing will ever consume, while the
    // caller believed the run had been told something.
    expect(fn () => app(Impex::class)->signal($run, 'approval'))
        ->toThrow(CannotSignalTerminalRunException::class, 'nothing will ever consume it');

    expect(Signal::query()->count())->toBe(0);
});

it('treats a finished run as a no-op for signalIfRunning', function (): void {
    $finished = app(Impex::class)->run('linear', [1])->refresh();
    $waiting = app(Impex::class)->run('signal')->refresh();

    expect(app(Impex::class)->signalIfRunning($finished, 'approval'))->toBeFalse()
        ->and(app(Impex::class)->signalIfRunning($waiting, 'approval', ['approved' => true]))->toBeTrue()
        ->and($waiting->refresh()->status)->toBe(RunStatus::Completed);
});

it('does not deliver the same signal twice for one idempotency key', function (): void {
    $run = app(Impex::class)->run('signal');

    app(Impex::class)->signal($run, 'approval', ['approved' => true], idempotencyKey: 'evt_1');
    app(Impex::class)->signalIfRunning($run->refresh(), 'approval', ['approved' => true], idempotencyKey: 'evt_1');

    expect(Signal::query()->count())->toBe(1);
});

it('scopes to signalable runs, which running() would miss', function (): void {
    app(Impex::class)->run('signal');           // waiting
    app(Impex::class)->run('linear', [1]);      // completed

    $signalable = Run::query()->signalable()->get();

    // The whole point: a run parked on a signal is exactly the one you want,
    // and it is not "running".
    expect($signalable)->toHaveCount(1)
        ->and($signalable[0]->status)->toBe(RunStatus::Waiting);
});

it('returns the default when a wait times out', function (): void {
    $run = app(Impex::class)->run('timeout');

    expect($run->refresh()->status)->toBe(RunStatus::Waiting);

    Carbon::setTestNow(now()->addDays(4));
    $this->artisan('impex:tick')->assertSuccessful();
    Carbon::setTestNow();

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and(app(Impex::class)->result($run))->toBe(['approved' => false, 'timed_out' => true]);
});

it('distinguishes a timeout from a signal whose payload was null', function (): void {
    $timedOut = app(Impex::class)->run('timeout');
    $signalled = app(Impex::class)->run('null-signal');

    // A null payload is a real answer, not an absent one.
    app(Impex::class)->signal($signalled, 'approval', null);

    Carbon::setTestNow(now()->addDays(4));
    $this->artisan('impex:tick')->assertSuccessful();
    Carbon::setTestNow();

    expect(app(Impex::class)->result($timedOut->refresh()))->toBe(['approved' => false, 'timed_out' => true])
        ->and(app(Impex::class)->result($signalled->refresh()))->toBe(['received' => null, 'timed_out' => false]);

    $step = $timedOut->forwardSteps()->where('type', StepType::Signal)->first();

    expect($step->status)->toBe(StepStatus::Skipped);
});

it('fails the run on timeout when the flow asked for orFail', function (): void {
    $run = app(Impex::class)->run('strict');

    Carbon::setTestNow(now()->addDays(4));
    $this->artisan('impex:tick')->assertSuccessful();
    Carbon::setTestNow();

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and($run->error['class'])->toBe(SignalTimeoutException::class);
});

it('delivers a signal from the console', function (): void {
    $run = app(Impex::class)->run('signal');

    $this->artisan('impex:signal', [
        'run' => (string) $run->getKey(),
        'name' => 'approval',
        '--payload' => '{"approved":true}',
    ])->assertSuccessful();

    expect($run->refresh()->status)->toBe(RunStatus::Completed);
});

it('rejects invalid JSON on the console rather than delivering nothing', function (): void {
    $run = app(Impex::class)->run('signal');

    $this->artisan('impex:signal', [
        'run' => (string) $run->getKey(),
        'name' => 'approval',
        '--payload' => '{not json',
    ])->assertFailed();

    expect($run->refresh()->status)->toBe(RunStatus::Waiting);
});

it('reports a terminal run on the console instead of failing with --if-running', function (): void {
    $run = app(Impex::class)->run('linear', [1]);

    $this->artisan('impex:signal', [
        'run' => (string) $run->getKey(),
        'name' => 'approval',
        '--if-running' => true,
    ])->assertSuccessful();

    $this->artisan('impex:signal', [
        'run' => (string) $run->getKey(),
        'name' => 'approval',
    ])->assertFailed();
});

it('answers 200 with nothing delivered for if_running over the API', function (): void {
    $run = app(Impex::class)->run('linear', [1]);

    $this->postJson('/impex/runs/'.$run->getKey().'/signals', [
        'name' => 'approval',
        'if_running' => true,
    ])->assertOk()->assertJsonPath('data', null);
});

it('reports delivered false over MCP for a finished run', function (): void {
    $run = app(Impex::class)->run('linear', [1]);

    ImpexServer::tool(SignalRunTool::class, [
        'run' => (string) $run->getKey(),
        'name' => 'approval',
        'if_running' => true,
    ])->assertOk()->assertSee('delivered');
});

it('answers 409 when signalling a finished run over the API', function (): void {
    $run = app(Impex::class)->run('linear', [1]);

    // Conflict, not 422: the request was well-formed, the run's state makes it
    // impossible.
    $this->postJson('/impex/runs/'.$run->getKey().'/signals', ['name' => 'approval'])
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'nothing will ever consume it'));
});
