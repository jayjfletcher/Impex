<?php

declare(strict_types=1);

use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\StepType;
use JayI\Impex\Exceptions\FanOutTooLargeException;
use JayI\Impex\Impex;
use JayI\Impex\Runtime\Engine;
use JayI\Impex\Tests\Fixtures\Calls;
use JayI\Impex\Tests\Fixtures\FanOutFlow;
use JayI\Impex\Tests\Fixtures\UnkeyedFanOutFlow;

beforeEach(function (): void {
    Calls::reset();
    config()->set('queue.default', 'sync');
    config()->set('impex.flows', [
        'fan-out' => FanOutFlow::class,
        'unkeyed' => UnkeyedFanOutFlow::class,
    ]);
});

it('runs one step per item and returns results in key order', function (): void {
    $run = app(Impex::class)->run('fan-out', [4]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and(app(Impex::class)->result($run))->toBe(['values' => [1, 2, 3, 4]])
        ->and(Calls::count('add-one'))->toBe(4);

    // One step for the source, one marker, four branches.
    expect($run->forwardSteps()->count())->toBe(6);

    $marker = $run->forwardSteps()->where('sequence', 1)->first();

    expect($marker->type)->toBe(StepType::FanOut);
});

it('records the collection fingerprint on the marker step', function (): void {
    $run = app(Impex::class)->run('fan-out', [3]);

    $marker = $run->forwardSteps()->where('sequence', 1)->first();

    /** @var array<string, mixed> $result */
    $result = $marker->result['value'];

    expect($result['count'])->toBe(3)
        ->and($result['keys'])->toBe(['SKU-000', 'SKU-001', 'SKU-002'])
        ->and($result['fingerprint'])->toBeString()->toHaveLength(64);
});

it('fingerprints the whole collection when no key is given', function (): void {
    $run = app(Impex::class)->run('unkeyed', [3]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed);

    $marker = $run->forwardSteps()->where('sequence', 1)->first();

    // Positional keys, because position is the identity without keyBy.
    expect($marker->result['value']['keys'])->toBe([0, 1, 2]);
});

it('refuses a collection larger than the configured cap', function (): void {
    config()->set('impex.limits.fan_out_max', 3);

    $run = app(Impex::class)->run('fan-out', [10]);

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and($run->error['class'])->toBe(FanOutTooLargeException::class)
        ->and($run->error['message'])->toContain('batch() for collections of this size');
});

it('does not re-run fan-out branches when the run is driven again', function (): void {
    $run = app(Impex::class)->run('fan-out', [4]);

    expect(Calls::count('add-one'))->toBe(4);

    app(Engine::class)->drive((string) $run->getKey());

    expect(Calls::count('add-one'))->toBe(4);
});
