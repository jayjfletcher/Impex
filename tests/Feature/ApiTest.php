<?php

declare(strict_types=1);

use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Impex;
use JayI\Impex\Models\FlowOverride;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunOwner;
use JayI\Impex\Tests\Fixtures\Calls;
use JayI\Impex\Tests\Fixtures\LinearFlow;
use JayI\Impex\Tests\Fixtures\SignalFlow;
use Workbench\App\Models\User;

beforeEach(function (): void {
    Calls::reset();
    config()->set('queue.default', 'sync');
    config()->set('impex.flows', [
        'linear' => LinearFlow::class,
        'signal' => SignalFlow::class,
    ]);
});

it('does not mount the dashboard unless it is enabled', function (): void {
    // Ships disabled: the dashboard renders every payload that has crossed the
    // application boundary, so mounting it is an explicit decision.
    expect(app('router')->getRoutes()->getByName('impex.ui'))->toBeNull();
});

it('lists the registered flows with their effective schedule', function (): void {
    config()->set('impex.schedule', ['linear' => '0 * * * *']);

    $this->getJson('/impex/flows')
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'linear')
        ->assertJsonPath('data.0.enabled', true)
        ->assertJsonPath('data.0.schedule', '0 * * * *');
});

it('accepts a run and answers immediately rather than executing inline', function (): void {
    $this->postJson('/impex/flows/linear/runs', ['arguments' => [1]])
        // 202: the run is queued. A trigger endpoint must answer inside the
        // gateway's timeout however long the flow takes.
        ->assertStatus(202)
        ->assertJsonPath('data.flow', 'linear')
        ->assertJsonPath('data.status', 'completed');

    expect(Run::query()->count())->toBe(1);
});

it('returns the original run when an idempotency key is reused', function (): void {
    $payload = ['arguments' => [1], 'idempotency_key' => 'order-42'];

    $first = $this->postJson('/impex/flows/linear/runs', $payload)->assertStatus(202);
    $second = $this->postJson('/impex/flows/linear/runs', $payload)->assertStatus(202);

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Calls::count('add-one'))->toBe(1);
});

it('refuses to run a flow disabled by a database override', function (): void {
    FlowOverride::query()->create(['slug' => 'linear', 'enabled' => false]);

    $this->postJson('/impex/flows/linear/runs', ['arguments' => [1]])->assertStatus(500);

    expect(Run::query()->count())->toBe(0);
});

it('validates run filters', function (): void {
    $this->getJson('/impex/runs?status=nonsense')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('filters runs by status, flow, and tag', function (): void {
    app(Impex::class)->run('linear', [1], tags: ['tenant' => 'acme']);
    app(Impex::class)->run('signal');

    $this->getJson('/impex/runs?status=completed')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.flow', 'linear');

    $this->getJson('/impex/runs?tag[tenant]=acme')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson('/impex/runs?status=waiting')
        ->assertOk()
        ->assertJsonPath('data.0.flow', 'signal');
});

it('shows a run with its owners and step history', function (): void {
    $run = app(Impex::class)->run('linear', [1]);

    $this->getJson('/impex/runs/'.$run->getKey())
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonCount(2, 'data.steps')
        ->assertJsonPath('data.steps.0.sequence', 0)
        // Results are never inlined: a step result can be hundreds of
        // megabytes on the artifact disk.
        ->assertJsonPath('data.steps.0.has_result', true)
        ->assertJsonMissingPath('data.steps.0.result');
});

it('lists a run steps including the compensation phase', function (): void {
    $run = app(Impex::class)->run('linear', [1]);

    $this->getJson('/impex/runs/'.$run->getKey().'/steps')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson('/impex/runs/'.$run->getKey().'/steps?phase=compensation')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('signals a waiting run over the API', function (): void {
    $run = app(Impex::class)->run('signal');

    expect($run->refresh()->status)->toBe(RunStatus::Waiting);

    $this->postJson('/impex/runs/'.$run->getKey().'/signals', [
        'name' => 'approval',
        'payload' => ['approved' => true],
    ])->assertStatus(202)->assertJsonPath('data.name', 'approval');

    expect($run->refresh()->status)->toBe(RunStatus::Completed);
});

it('cancels a run', function (): void {
    $run = app(Impex::class)->run('signal');

    $this->postJson('/impex/runs/'.$run->getKey().'/cancel', ['reason' => 'no longer needed'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.error.message', 'no longer needed');
});

it('attaches and detaches run owners', function (): void {
    $run = app(Impex::class)->run('linear', [1]);
    $user = User::forceCreate(['name' => 'Jay', 'email' => 'jay@example.test', 'password' => 'x']);

    $created = $this->postJson('/impex/runs/'.$run->getKey().'/owners', [
        'owner_type' => $user->getMorphClass(),
        'owner_id' => (string) $user->getKey(),
        'role' => 'customer',
    ])->assertStatus(201);

    $this->getJson('/impex/runs/'.$run->getKey().'/owners')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.role', 'customer');

    // The morph triple is not addressable on its own, which is why owners
    // carry a surrogate key.
    $this->deleteJson('/impex/runs/'.$run->getKey().'/owners/'.$created->json('data.id'))
        ->assertStatus(204);

    expect(RunOwner::query()->count())->toBe(0);
});

it('filters runs by owner', function (): void {
    $user = User::forceCreate(['name' => 'Jay', 'email' => 'jay@example.test', 'password' => 'x']);

    $mine = app(Impex::class)->run('linear', [1], owners: ['customer' => $user]);
    app(Impex::class)->run('linear', [2]);

    $this->getJson(sprintf(
        '/impex/runs?owner_type=%s&owner_id=%s',
        urlencode($user->getMorphClass()),
        $user->getKey(),
    ))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->getKey());
});

it('lists the ledger and a single message', function (): void {
    app(Impex::class)->record(channel: 'sftp-drop', endpoint: 'sftp://partner.test/out.csv', body: 'a,b');

    $this->getJson('/impex/messages')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.channel', 'sftp-drop')
        ->assertJsonPath('data.0.direction', 'outbound');

    $id = $this->getJson('/impex/messages')->json('data.0.id');

    $this->getJson('/impex/messages/'.$id)
        ->assertOk()
        ->assertJsonPath('data.body_preview', 'a,b');
});

it('lists channels without leaking their signing secrets', function (): void {
    config()->set('impex.channels', [
        'supplier-feed' => ['signing_secret' => 'shhh', 'flow' => 'linear'],
    ]);

    $response = $this->getJson('/impex/channels')->assertOk();

    expect($response->json('data.0'))->toBe([
        'name' => 'supplier-feed',
        'direction' => 'inbound',
        'verifies_signatures' => true,
        'flow' => 'linear',
        'path' => null,
    ]);

    expect($response->content())->not->toContain('shhh');
});
