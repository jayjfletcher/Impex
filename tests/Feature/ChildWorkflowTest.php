<?php

declare(strict_types=1);

use JayI\Impex\Enums\ChildClosePolicy;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\RunTrigger;
use JayI\Impex\Enums\StepType;
use JayI\Impex\Impex;
use JayI\Impex\Models\Run;
use JayI\Impex\Runtime\Engine;
use JayI\Impex\Testing\Flows;
use JayI\Impex\Tests\Fixtures\Calls;
use JayI\Impex\Tests\Fixtures\ChildFlow;
use JayI\Impex\Tests\Fixtures\DetachedParentFlow;
use JayI\Impex\Tests\Fixtures\FailingChildFlow;
use JayI\Impex\Tests\Fixtures\FailingParentFlow;
use JayI\Impex\Tests\Fixtures\ParentFlow;
use JayI\Impex\Tests\Fixtures\Rollback;
use JayI\Impex\Tests\Fixtures\SignalFlow;

beforeEach(function (): void {
    Calls::reset();
    config()->set('queue.default', 'sync');
    config()->set('impex.flows', [
        'parent' => ParentFlow::class,
        'child' => ChildFlow::class,
        'failing-parent' => FailingParentFlow::class,
        'failing-child' => FailingChildFlow::class,
        'detached-parent' => DetachedParentFlow::class,
        'waiting-child' => SignalFlow::class,
    ]);
});

it('runs a child as a run in its own right and returns its result', function (): void {
    $parent = app(Impex::class)->run('parent', [5]);

    Flows::assertCompleted($parent);

    expect(app(Impex::class)->result($parent->refresh()))->toBe(['child' => ['value' => 6]])
        // The child's action ran once. Its handle() ran more than once, as any
        // replayed handle() does — which is why work belongs in actions.
        ->and(Calls::count('add-one'))->toBe(1);

    $child = Run::query()->where('parent_run_id', $parent->getKey())->firstOrFail();

    // Its own history, its own row — not a step hidden inside the parent.
    expect($child->flow)->toBe('child')
        ->and($child->trigger)->toBe(RunTrigger::Child)
        ->and($child->parent_sequence)->toBe(0)
        ->and($child->status)->toBe(RunStatus::Completed);
});

it('records the child as a single step in the parent history', function (): void {
    $parent = app(Impex::class)->run('parent', [5]);

    Flows::assertForwardStepCount($parent->refresh(), 1);

    expect($parent->forwardSteps()->first()->type)->toBe(StepType::Child);
});

it('fails the parent step when the child fails, and roll backs the parent', function (): void {
    $parent = app(Impex::class)->run('failing-parent');

    Flows::assertFailed($parent);

    // The parent unwinds its own work, exactly as any failed step would.
    Flows::assertRolledBack($parent->refresh(), Rollback::class);

    expect(Calls::count('never-reached'))->toBe(0);
});

it('completes a detached child step immediately without waiting', function (): void {
    $parent = app(Impex::class)->run('detached-parent');

    $result = app(Impex::class)->result(Flows::assertCompleted($parent));

    expect($result['detached'])->toBeTrue()
        ->and($result['run_id'])->toBeString();
});

it('cancels running children when the parent finishes under a Cancel policy', function (): void {
    // The parent parks on a signal, so it is still unfinished while the child
    // is attached; signalling it is what triggers the close policy.
    $parent = app(Impex::class)->run('waiting-child');
    $child = app(Impex::class)->run('waiting-child');

    $child->update(['parent_run_id' => $parent->getKey(), 'close_policy' => ChildClosePolicy::Cancel]);

    app(Impex::class)->signal($parent, 'approval', ['approved' => true]);

    expect($parent->refresh()->status)->toBe(RunStatus::Completed)
        ->and($child->refresh()->status)->toBe(RunStatus::Cancelled)
        ->and($child->error['message'])->toContain('parent run finished');
});

it('leaves children alone under the default Abandon policy', function (): void {
    $parent = app(Impex::class)->run('waiting-child');
    $child = app(Impex::class)->run('waiting-child');

    $child->update(['parent_run_id' => $parent->getKey(), 'close_policy' => ChildClosePolicy::Abandon]);

    app(Impex::class)->signal($parent, 'approval', ['approved' => true]);

    expect($parent->refresh()->status)->toBe(RunStatus::Completed)
        ->and($child->refresh()->status)->toBe(RunStatus::Waiting);
});

it('gives the child the parent queue routing and version', function (): void {
    $parent = app(Impex::class)->run('parent', [1], version: 'v7');
    $parent->update(['queue' => 'impex-bulk']);

    app(Engine::class)->drive((string) $parent->getKey());

    $child = Run::query()->where('parent_run_id', $parent->getKey())->firstOrFail();

    expect($child->flow_version)->toBe('v7');
});
