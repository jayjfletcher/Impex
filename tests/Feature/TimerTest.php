<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Enums\StepType;
use JayI\Impex\Exceptions\StalledStepException;
use JayI\Impex\Impex;
use JayI\Impex\Models\FlowOverride;
use JayI\Impex\Models\Timer;
use JayI\Impex\Tests\Fixtures\Calls;
use JayI\Impex\Tests\Fixtures\LinearFlow;
use JayI\Impex\Tests\Fixtures\SleepingFlow;
use JayI\Impex\Tests\Fixtures\StallingFlow;

beforeEach(function (): void {
    Calls::reset();
    config()->set('queue.default', 'sync');
    config()->set('impex.flows', [
        'linear' => LinearFlow::class,
        'sleeping' => SleepingFlow::class,
        'stalling' => StallingFlow::class,
    ]);
});

it('records a long wait as a timer rather than a delayed job', function (): void {
    $run = app(Impex::class)->run('sleeping');

    expect($run->refresh()->status)->toBe(RunStatus::Waiting)
        ->and(Calls::count('after-sleep'))->toBe(0);

    $timer = Timer::query()->where('run_id', $run->getKey())->first();

    // Three days is far past SQS's 15-minute delay ceiling, which is the whole
    // reason this table exists.
    expect($timer)->not->toBeNull()
        ->and($timer->wake_at->isAfter(Carbon::now()->addHours(70)))->toBeTrue()
        ->and($timer->fired_at)->toBeNull();

    $step = $run->forwardSteps()->where('sequence', 1)->first();

    expect($step->type)->toBe(StepType::Timer);
});

it('wakes a sleeping run when the tick sweep fires its timer', function (): void {
    $run = app(Impex::class)->run('sleeping');

    expect($run->refresh()->status)->toBe(RunStatus::Waiting);

    Carbon::setTestNow(Carbon::now()->addDays(4));

    $this->artisan('impex:tick')->assertSuccessful();

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and(Calls::count('after-sleep'))->toBe(1)
        ->and(Timer::query()->where('run_id', $run->getKey())->first()->fired_at)->not->toBeNull();

    Carbon::setTestNow();
});

it('will not fire the same timer twice across sweeps', function (): void {
    $run = app(Impex::class)->run('sleeping');

    Carbon::setTestNow(Carbon::now()->addDays(4));

    $this->artisan('impex:tick')->assertSuccessful();
    $this->artisan('impex:tick')->assertSuccessful();
    $this->artisan('impex:tick')->assertSuccessful();

    expect(Calls::count('after-sleep'))->toBe(1);

    Carbon::setTestNow();
});

it('fails a resumable step that yields without advancing its cursor', function (): void {
    $run = app(Impex::class)->run('stalling');

    // Two invocations, not an unbounded loop: the second yields the cursor the
    // first already stored, and the engine stops it there.
    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and(Calls::count('never-advances'))->toBe(2);

    $step = $run->forwardSteps()->first();

    // The step carries the cause; the run carries the replay's view of it.
    expect($step->status)->toBe(StepStatus::Failed)
        ->and($step->error['class'])->toBe(StalledStepException::class)
        ->and($step->resumptions)->toBe(1)
        ->and($run->error['message'])->toContain('not making progress');
});

it('honours a database override that disables a registered flow', function (): void {
    expect(app(Impex::class)->flows()->enabled('linear'))->toBeTrue();

    FlowOverride::query()->create(['slug' => 'linear', 'enabled' => false]);

    expect(app(Impex::class)->flows()->enabled('linear'))->toBeFalse()
        // The registry still knows the flow exists — code is the source of
        // truth for that; the table only decides whether it may run.
        ->and(app(Impex::class)->flows()->has('linear'))->toBeTrue();
});

it('prefers a database schedule override over the configured one', function (): void {
    config()->set('impex.schedule', ['linear' => '0 * * * *']);

    expect(app(Impex::class)->flows()->schedule('linear'))->toBe('0 * * * *');

    FlowOverride::query()->create(['slug' => 'linear', 'schedule' => '*/5 * * * *']);

    expect(app(Impex::class)->flows()->schedule('linear'))->toBe('*/5 * * * *');
});
