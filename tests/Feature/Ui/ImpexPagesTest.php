<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use JayI\Impex\Enums\Direction;
use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Enums\RunTrigger;
use JayI\Impex\Enums\StepPhase;
use JayI\Impex\Enums\StepStatus;
use JayI\Impex\Enums\StepType;
use JayI\Impex\Models\Message;
use JayI\Impex\Models\Run;
use JayI\Impex\Tests\Fixtures\LinearFlow;
use JayI\Impex\Tests\Fixtures\SignalFlow;

beforeEach(function (): void {
    ValidateCsrfToken::except(['*']);

    // Atrium denies access outside local until a gate is defined.
    app()->detectEnvironment(fn (): string => 'local');

    config()->set('impex.flows', [
        'linear' => LinearFlow::class,
        'signal' => SignalFlow::class,
    ]);
});

function makeRun(RunStatus $status = RunStatus::Completed): Run
{
    return Run::query()->create([
        'flow' => 'linear',
        'flow_class' => LinearFlow::class,
        'status' => $status,
        'trigger' => RunTrigger::Api,
    ]);
}

it('lists runs', function (): void {
    makeRun();

    $this->get(route('atrium.impex.runs.index'))
        ->assertOk()
        ->assertSee('linear')
        ->assertSee('completed');
});

it('shows an empty state when nothing matches', function (): void {
    $this->get(route('atrium.impex.runs.index'))
        ->assertOk()
        ->assertSee(__('impex::impex.no_runs'));
});

it('offers every real run status as a filter', function (): void {
    $response = $this->get(route('atrium.impex.runs.index'))->assertOk();

    // Driven from the enum, so the filter cannot offer a status the API
    // would reject. The Vue UI offered "compensating", which is not a case.
    $response->assertSee('rolling_back')->assertDontSee('compensating');
});

it('filters runs by status', function (): void {
    makeRun(RunStatus::Completed);
    makeRun(RunStatus::Failed);

    $this->get(route('atrium.impex.runs.index', ['status' => 'failed']))
        ->assertOk()
        ->assertSee('failed');
});

it('rejects a status that is not a real case', function (): void {
    $this->get(route('atrium.impex.runs.index', ['status' => 'compensating']))
        ->assertSessionHasErrors('status');
});

it('shows a run with its steps and owners', function (): void {
    $run = makeRun();
    $run->steps()->create([
        'phase' => StepPhase::Forward,
        'sequence' => 1,
        'type' => StepType::Action,
        'name' => 'add-one',
        'status' => StepStatus::Completed,
    ]);

    $this->get(route('atrium.impex.runs.show', $run))
        ->assertOk()
        ->assertSee('add-one')
        ->assertSee(__('impex::impex.no_owners'));
});

it('hides the signal form unless the run is waiting', function (): void {
    $completed = makeRun(RunStatus::Completed);
    $waiting = makeRun(RunStatus::Waiting);

    $this->get(route('atrium.impex.runs.show', $completed))
        ->assertOk()
        ->assertDontSee(__('impex::impex.send_signal'));

    $this->get(route('atrium.impex.runs.show', $waiting))
        ->assertOk()
        ->assertSee(__('impex::impex.send_signal'));
});

it('hides cancel for a finished run', function (): void {
    $run = makeRun(RunStatus::Completed);

    $this->get(route('atrium.impex.runs.show', $run))
        ->assertOk()
        ->assertDontSee('data-testid="cancel-run"', false);
});

it('cancels a running run', function (): void {
    $run = makeRun(RunStatus::Running);

    $this->post(route('atrium.impex.runs.cancel', $run))->assertRedirect();

    expect($run->fresh()->status)->toBe(RunStatus::Cancelled);
});

it('lists messages', function (): void {
    Message::query()->create([
        'direction' => Direction::Inbound,
        'channel' => 'webhook',
        'endpoint' => '/hooks/example',
        'transport' => 'http',
        'bytes' => 42,
        'occurred_at' => now(),
    ]);

    $this->get(route('atrium.impex.messages.index'))
        ->assertOk()
        ->assertSee('webhook')
        ->assertSee('inbound');
});

it('shows a message with its body', function (): void {
    $message = Message::query()->create([
        'direction' => Direction::Outbound,
        'channel' => 'api',
        'endpoint' => 'https://example.test/hook',
        'transport' => 'http',
        'bytes' => 12,
        'body_preview' => '{"ok":true}',
        'occurred_at' => now(),
    ]);

    $this->get(route('atrium.impex.messages.show', $message))
        ->assertOk()
        ->assertSee('{&quot;ok&quot;:true}', false);
});

it('lists flows and starts one', function (): void {
    $this->get(route('atrium.impex.flows.index'))->assertOk()->assertSee('linear');

    $this->post(route('atrium.impex.flows.run', 'linear'), ['arguments' => '[1]'])
        ->assertRedirect();

    expect(Run::query()->where('flow', 'linear')->exists())->toBeTrue();
});

it('rejects arguments that are not a json array', function (): void {
    $this->post(route('atrium.impex.flows.run', 'linear'), ['arguments' => 'not json'])
        ->assertSessionHasErrors('arguments');
});

it('lists channels', function (): void {
    $this->get(route('atrium.impex.channels.index'))->assertOk();
});
