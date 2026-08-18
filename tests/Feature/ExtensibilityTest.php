<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use JayI\Impex\Contracts\RollbackStrategy;
use JayI\Impex\Impex;
use JayI\Impex\Jobs\DriveRun;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunStep;
use JayI\Impex\Runtime\EngineOptions;
use JayI\Impex\Runtime\JobRouter;
use JayI\Impex\Runtime\Rollbacks;
use JayI\Impex\Runtime\Sweeper;
use JayI\Impex\Runtime\SweepReport;
use JayI\Impex\Testing\Flows;
use JayI\Impex\Tests\Fixtures\Calls;
use JayI\Impex\Tests\Fixtures\LinearFlow;
use JayI\Impex\Tests\Fixtures\UnitFlow;

beforeEach(function (): void {
    Calls::reset();
    config()->set('queue.default', 'sync');
    config()->set('impex.flows', ['linear' => LinearFlow::class, 'unit' => UnitFlow::class]);
});

it('resolves the rollback strategy from the container', function (): void {
    expect(app(RollbackStrategy::class))->toBeInstanceOf(Rollbacks::class);
});

it('lets an application swap the rollback strategy without forking', function (): void {
    // A strategy that refuses to unwind anything.
    app()->bind(RollbackStrategy::class, fn (): RollbackStrategy => new class implements RollbackStrategy
    {
        public function next(Run $run): bool
        {
            return false;
        }

        public function halts(RunStep $rollbackStep): bool
        {
            return true;
        }
    });

    $run = app(Impex::class)->run('unit');

    Flows::assertFailed($run);

    // The default strategy would have rolled both steps back.
    Flows::assertNotRolledBack($run->refresh());
    expect(Calls::count('rollback:first'))->toBe(0);
});

it('refuses a lease shorter than the step window, rather than running steps twice', function (): void {
    config()->set('impex.limits.max_step_seconds', 840);
    config()->set('impex.limits.lease_seconds', 600);

    // Caught at read time with a message naming both values, instead of
    // surfacing as duplicated side effects in production.
    expect(fn (): int => app(EngineOptions::class)->leaseSeconds())
        ->toThrow(RuntimeException::class, 'must exceed');
});

it('reports what a sweep did, for a health check or a job', function (): void {
    $report = app(Sweeper::class)->sweep();

    expect($report)->toBeInstanceOf(SweepReport::class)
        ->and($report->idle())->toBeTrue()
        ->and($report->summary())->toContain('0 timer(s) fired');
});

it('routes engine jobs onto the run own queue when it has one', function (): void {
    config()->set('queue.default', 'null');
    config()->set('impex.queue.queue', 'impex-default');

    Queue::fake();

    $run = app(Impex::class)->run('linear', [1]);
    $run->update(['queue' => 'impex-bulk']);

    app(JobRouter::class)->drive($run);

    Queue::assertPushedOn('impex-bulk', DriveRun::class);
});
