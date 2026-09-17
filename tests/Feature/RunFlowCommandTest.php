<?php

declare(strict_types=1);

use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Facades\Impex;
use JayI\Impex\Models\Run;
use JayI\Impex\Tests\Fixtures\TypedArgumentsFlow;

beforeEach(function (): void {
    config()->set('queue.default', 'sync');
    config()->set('impex.flows', ['typed' => TypedArgumentsFlow::class]);
});

/** The result of the run the command just started. */
function lastRunResult(): array
{
    $run = Run::query()->latest('id')->firstOrFail();

    expect($run->status)->toBe(RunStatus::Completed);

    return Impex::result($run);
}

it('exposes a flow\'s parameters as named options', function (): void {
    // A boolean parameter reads like a flag, which is what a boolean means on a
    // command line; everything else takes a value.
    $this->artisan('impex:run', [
        'flow' => 'typed',
        '--initial' => true,
        '--since' => '2026-01-01',
        '--size' => '500',
        '--tolerance' => '1.5',
    ])->assertSuccessful();

    expect(lastRunResult())->toMatchArray([
        'since' => '2026-01-01',
        'initial' => true,
        'size' => 500,
        'tolerance' => 1.5,
    ]);
});

it('leaves untouched parameters on their declared defaults', function (): void {
    // The whole point of naming: reaching the third parameter no longer means
    // padding the first two, so their defaults stand rather than being
    // overwritten with a null that means something different.
    $this->artisan('impex:run', ['flow' => 'typed', '--initial' => true])
        ->assertSuccessful();

    expect(lastRunResult())->toMatchArray([
        'since' => null,
        'ids' => null,
        'initial' => true,
        'size' => 100,
        'tolerance' => 0.5,
    ]);
});

it('casts named option strings to the types handle() declares', function (): void {
    // Console values are strings and flow files declare strict_types, so without
    // casting these raise a TypeError inside the engine.
    $this->artisan('impex:run', ['flow' => 'typed', '--size' => '500'])
        ->assertSuccessful();

    $result = lastRunResult();

    expect($result['size'])->toBe(500)
        ->and($result['size'])->toBeInt();
});

it('camel-cases a parameter into a dashed option name', function (): void {
    // $batchSize reads as --batch-size, matching every other console option.
    $this->artisan('impex:run', ['flow' => 'typed', '--batch-size' => '25'])
        ->assertSuccessful();

    expect(lastRunResult()['batchSize'])->toBe(25);
});

it('still accepts positional arguments', function (): void {
    // The older spelling keeps working; scripts may rely on it.
    $this->artisan('impex:run', [
        'flow' => 'typed',
        '--argument' => ['2026-01-01', 'a,b', '1'],
    ])->assertSuccessful();

    expect(lastRunResult())->toMatchArray([
        'since' => '2026-01-01',
        // A union already accepts the string, so it is passed through rather
        // than guessed at.
        'ids' => 'a,b',
        'initial' => true,
    ]);
});

it('lets a named option win over a positional one', function (): void {
    $this->artisan('impex:run', [
        'flow' => 'typed',
        '--argument' => ['2026-01-01'],
        '--since' => '2026-06-30',
    ])->assertSuccessful();

    expect(lastRunResult()['since'])->toBe('2026-06-30');
});

it('reads an empty positional argument as null', function (): void {
    $this->artisan('impex:run', [
        'flow' => 'typed',
        '--argument' => ['', '', '1'],
    ])->assertSuccessful();

    expect(lastRunResult())->toMatchArray(['since' => null, 'ids' => null, 'initial' => true]);
});

it('accepts the spellings a person actually types for a boolean', function (string $given): void {
    $this->artisan('impex:run', ['flow' => 'typed', '--argument' => ['', '', $given]])
        ->assertSuccessful();

    expect(lastRunResult()['initial'])->toBeTrue();
})->with(['1', 'true', 'yes', 'on', 'TRUE']);

it('reads a false boolean as false rather than as truthy text', function (string $given): void {
    $this->artisan('impex:run', ['flow' => 'typed', '--argument' => ['', '', $given]])
        ->assertSuccessful();

    expect(lastRunResult()['initial'])->toBeFalse();
})->with(['0', 'false', 'no', 'off']);

it('rejects a boolean it cannot read instead of silently running incrementally', function (): void {
    // A silent false here would turn a requested full sweep into an incremental
    // one — the run would look successful and quietly index almost nothing.
    $this->artisan('impex:run', ['flow' => 'typed', '--argument' => ['', '', 'maybe']])
        ->assertFailed();

    expect(Run::query()->count())->toBe(0);
});

it('rejects a non-numeric integer instead of casting it to zero', function (): void {
    // A bare (int) cast would make this 0, which for a batch size or a limit is
    // a silently empty run.
    $this->artisan('impex:run', ['flow' => 'typed', '--size' => 'lots'])->assertFailed();

    expect(Run::query()->count())->toBe(0);
});

it('leaves a flow with no arguments alone', function (): void {
    $this->artisan('impex:run', ['flow' => 'typed'])->assertSuccessful();

    expect(lastRunResult())->toMatchArray([
        'since' => null,
        'initial' => false,
        'size' => 100,
    ]);
});

it('persists named arguments by name, so a replay applies them the same way', function (): void {
    $this->artisan('impex:run', ['flow' => 'typed', '--initial' => true, '--size' => '500'])
        ->assertSuccessful();

    $run = Run::query()->latest('id')->firstOrFail();

    // Keys survive into the stored payload rather than being flattened to
    // positions, so the history records what was actually asked for.
    expect(Impex::result($run))->toMatchArray(['initial' => true, 'size' => 500]);
});

it('reports an unregistered flow rather than failing to parse', function (): void {
    // Options are added by reflecting the named flow, so an unknown slug has
    // none to add. That must not become a parse error in place of the real
    // message.
    $this->artisan('impex:run', ['flow' => 'nope'])->assertFailed();

    expect(Run::query()->count())->toBe(0);
});
