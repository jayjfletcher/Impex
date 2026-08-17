<?php

declare(strict_types=1);

use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Exceptions\FlowCollisionException;
use JayI\Impex\Exceptions\UnknownFlowException;
use JayI\Impex\Flows\FlowRegistry;
use JayI\Impex\Impex;
use JayI\Impex\Tests\Fixtures\LinearFlow;
use JayI\Impex\Tests\Fixtures\ParallelFlow;
use JayI\Impex\Tests\Fixtures\SignalFlow;

it('lets a package register a flow at runtime', function (): void {
    $registry = app(FlowRegistry::class);

    $registry->register('vendor-sync', LinearFlow::class);

    expect($registry->has('vendor-sync'))->toBeTrue()
        ->and($registry->class('vendor-sync'))->toBe(LinearFlow::class);
});

it('registers several flows at once, deriving slugs where none is given', function (): void {
    $registry = app(FlowRegistry::class);

    $registry->registerMany([
        'named' => LinearFlow::class,
        SignalFlow::class,
    ]);

    expect($registry->has('named'))->toBeTrue()
        ->and($registry->has('signal-flow'))->toBeTrue();
});

it('gives the application config precedence over a package registration', function (): void {
    $registry = app(FlowRegistry::class);

    $registry->register('shared', LinearFlow::class);
    config()->set('impex.flows', ['shared' => SignalFlow::class]);

    expect($registry->class('shared'))->toBe(SignalFlow::class)
        // The package's own view is still readable, which is what makes the
        // override visible rather than mysterious.
        ->and($registry->registered()['shared'])->toBe(LinearFlow::class);
});

it('keeps that precedence whatever order things happen in', function (): void {
    $registry = app(FlowRegistry::class);

    config()->set('impex.flows', ['shared' => SignalFlow::class]);

    // Reading first used to merge config permanently, so a package that booted
    // afterwards silently won. Precedence must not depend on this.
    $registry->all();
    $registry->register('shared', LinearFlow::class);

    expect($registry->class('shared'))->toBe(SignalFlow::class);
});

it('refuses to let two packages claim the same slug', function (): void {
    $registry = app(FlowRegistry::class);

    $registry->register('sync', LinearFlow::class);

    expect(fn () => $registry->register('sync', ParallelFlow::class))
        ->toThrow(FlowCollisionException::class, 'already registered');
});

it('is idempotent when the same class is registered twice', function (): void {
    $registry = app(FlowRegistry::class);

    // A provider that boots more than once must not blow up.
    $registry->register('sync', LinearFlow::class);
    $registry->register('sync', LinearFlow::class);

    expect($registry->registered())->toHaveCount(1);
});

it('rejects a class that is not a flow', function (): void {
    expect(fn () => app(FlowRegistry::class)->register('bad', stdClass::class))
        ->toThrow(UnknownFlowException::class);
});

it('rejects a non-flow declared in config, naming the class', function (): void {
    config()->set('impex.flows', ['bad' => stdClass::class]);

    expect(fn () => app(FlowRegistry::class)->all())
        ->toThrow(UnknownFlowException::class, 'stdClass');
});

it('runs a flow a package registered, end to end', function (): void {
    config()->set('queue.default', 'sync');

    app(FlowRegistry::class)->register('vendor-sync', LinearFlow::class);

    $run = app(Impex::class)->run('vendor-sync', [1]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and($run->flow)->toBe('vendor-sync');
});
