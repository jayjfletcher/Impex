<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use JayI\Impex\Impex;
use JayI\Impex\ImpexServiceProvider;
use JayI\Impex\Mcp\Tools\AttachRunOwnerTool;
use JayI\Impex\Mcp\Tools\CancelRunTool;
use JayI\Impex\Mcp\Tools\DetachRunOwnerTool;
use JayI\Impex\Mcp\Tools\ListRunsTool;
use JayI\Impex\Mcp\Tools\RunFlowTool;
use JayI\Impex\Mcp\Tools\ShowMessageTool;
use JayI\Impex\Mcp\Tools\ShowRunTool;
use JayI\Impex\Models\Artifact;
use JayI\Impex\Models\Batch;
use JayI\Impex\Models\BatchItem;
use JayI\Impex\Models\FlowOverride;
use JayI\Impex\Models\Message;
use JayI\Impex\Models\Run;
use JayI\Impex\Models\RunOwner;
use JayI\Impex\Models\RunStep;
use JayI\Impex\Models\Signal;
use JayI\Impex\Models\Timer;
use JayI\Impex\Policies\ArtifactPolicy;
use JayI\Impex\Policies\BatchItemPolicy;
use JayI\Impex\Policies\BatchPolicy;
use JayI\Impex\Policies\FlowOverridePolicy;
use JayI\Impex\Policies\MessagePolicy;
use JayI\Impex\Policies\RunOwnerPolicy;
use JayI\Impex\Policies\RunPolicy;
use JayI\Impex\Policies\RunStepPolicy;
use JayI\Impex\Policies\SignalPolicy;
use JayI\Impex\Policies\TimerPolicy;
use JayI\Impex\Tests\Fixtures\Calls;
use JayI\Impex\Tests\Fixtures\LinearFlow;
use JayI\Impex\Tests\Fixtures\Policies\KeepOwnersPolicy;
use JayI\Impex\Tests\Fixtures\Policies\ReadOnlyRunPolicy;
use JayI\Impex\Tests\Fixtures\SignalFlow;
use Workbench\App\Models\User;

beforeEach(function (): void {
    Calls::reset();
    config()->set('queue.default', 'sync');
    config()->set('impex.flows', [
        'linear' => LinearFlow::class,
        'signal' => SignalFlow::class,
    ]);
    config()->set('impex.authorization', true);

    $this->ann = User::forceCreate(['name' => 'Ann', 'email' => 'ann@example.test', 'password' => 'x']);
    $this->bob = User::forceCreate(['name' => 'Bob', 'email' => 'bob@example.test', 'password' => 'x']);
});

/**
 * Register the policies again after a test changes `impex.policies`, as the
 * provider does on boot.
 *
 * @param  array<class-string, class-string>  $policies
 */
function useImpexPolicies(array $policies): void
{
    foreach ($policies as $model => $policy) {
        config()->set('impex.policies.'.$model, $policy);
    }

    $provider = app()->getProvider(ImpexServiceProvider::class);

    (fn () => $this->registerPolicies())->call($provider);
}

it('registers the policies from the config', function (): void {
    expect(Gate::getPolicyFor(Run::class))->toBeInstanceOf(RunPolicy::class)
        ->and(Gate::getPolicyFor(RunStep::class))->toBeInstanceOf(RunStepPolicy::class)
        ->and(Gate::getPolicyFor(RunOwner::class))->toBeInstanceOf(RunOwnerPolicy::class)
        ->and(Gate::getPolicyFor(Signal::class))->toBeInstanceOf(SignalPolicy::class)
        ->and(Gate::getPolicyFor(Timer::class))->toBeInstanceOf(TimerPolicy::class)
        ->and(Gate::getPolicyFor(Batch::class))->toBeInstanceOf(BatchPolicy::class)
        ->and(Gate::getPolicyFor(BatchItem::class))->toBeInstanceOf(BatchItemPolicy::class)
        ->and(Gate::getPolicyFor(Message::class))->toBeInstanceOf(MessagePolicy::class)
        ->and(Gate::getPolicyFor(Artifact::class))->toBeInstanceOf(ArtifactPolicy::class)
        ->and(Gate::getPolicyFor(FlowOverride::class))->toBeInstanceOf(FlowOverridePolicy::class);
});

it('lets a run owner do anything with it, in any role', function (): void {
    $run = app(Impex::class)->run('linear', [1], owners: ['customer' => $this->ann]);

    expect($this->ann->can('view', $run))->toBeTrue()
        ->and($this->ann->can('cancel', $run))->toBeTrue()
        ->and($this->ann->can('share', $run))->toBeTrue()
        // An ability the application invents works for owners too.
        ->and($this->ann->can('export', $run))->toBeTrue()
        ->and($this->bob->can('view', $run))->toBeFalse()
        ->and($this->bob->can('export', $run))->toBeFalse()
        ->and($this->bob->can('viewAny', Run::class))->toBeTrue()
        ->and($this->bob->can('create', [Run::class, 'linear']))->toBeTrue()
        ->and($this->bob->can('viewAny', FlowOverride::class))->toBeTrue();
});

it('checks steps, owners, signals, messages and artifacts against their run', function (): void {
    $run = app(Impex::class)->run('linear', [1], owners: ['user' => $this->ann]);
    $step = $run->steps()->firstOrFail();
    $owner = $run->owners()->firstOrFail();
    $message = app(Impex::class)->record(channel: 'sftp-drop', endpoint: 'sftp://partner.test/a.csv', body: 'a', runId: $run->id);
    $orphan = app(Impex::class)->record(channel: 'sftp-drop', endpoint: 'sftp://partner.test/b.csv', body: 'b');
    $artifact = Artifact::factory()->create(['run_id' => $run->id]);

    expect($this->ann->can('viewAny', [RunStep::class, $run]))->toBeTrue()
        ->and($this->ann->can('view', $step))->toBeTrue()
        ->and($this->ann->can('update', $step))->toBeFalse()
        ->and($this->ann->can('create', [RunOwner::class, $run]))->toBeTrue()
        ->and($this->ann->can('delete', $owner))->toBeTrue()
        ->and($this->ann->can('create', [Signal::class, $run]))->toBeTrue()
        ->and($this->ann->can('view', $message))->toBeTrue()
        ->and($this->ann->can('view', $orphan))->toBeFalse()
        ->and($this->ann->can('view', $artifact))->toBeTrue()
        ->and($this->bob->can('view', $step))->toBeFalse()
        ->and($this->bob->can('delete', $owner))->toBeFalse()
        ->and($this->bob->can('create', [Signal::class, $run]))->toBeFalse()
        ->and($this->bob->can('view', $message))->toBeFalse()
        ->and($this->bob->can('view', $artifact))->toBeFalse();
});

it('leaves the API to the route middleware while authorization is off', function (): void {
    config()->set('impex.authorization', false);

    $run = app(Impex::class)->run('linear', [1]);

    $this->getJson('/impex/runs/'.$run->id)->assertOk();
    mcpTool(ShowRunTool::class, ['run' => $run->id])->assertOk();
});

it('refuses a guest once authorization is on', function (): void {
    $this->getJson('/impex/runs')->assertForbidden();
    mcpTool(ListRunsTool::class)->assertHasErrors(['Unauthorized.']);
});

it('makes the caller the owner of the runs they start, and lists only those', function (): void {
    app(Impex::class)->run('linear', [2]);

    $id = $this->actingAs($this->ann)
        ->postJson('/impex/flows/linear/runs', ['arguments' => [1]])
        ->assertStatus(202)
        ->json('data.id');

    $this->actingAs($this->ann)->getJson('/impex/runs')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id);

    $this->actingAs($this->ann)->getJson('/impex/runs/'.$id)->assertOk();
    $this->actingAs($this->ann)->getJson('/impex/runs/'.$id.'/steps')->assertOk();
    $this->actingAs($this->bob)->getJson('/impex/runs/'.$id)->assertForbidden();
    $this->actingAs($this->bob)->getJson('/impex/runs/'.$id.'/steps')->assertForbidden();
    $this->actingAs($this->bob)->getJson('/impex/runs/'.$id.'/owners')->assertForbidden();
    $this->actingAs($this->bob)->postJson('/impex/runs/'.$id.'/retry')->assertForbidden();
    $this->actingAs($this->bob)->getJson('/impex/runs')->assertOk()->assertJsonCount(0, 'data');

    $this->actingAs($this->bob);
    $mcp = mcpTool(RunFlowTool::class, ['flow' => 'linear', 'arguments' => [3]])->assertOk();
    mcpTool(ListRunsTool::class)->assertOk()->assertStructuredContent(fn ($json) => $json->has('data', 1)->etc());
    mcpTool(ShowRunTool::class, ['run' => $id])->assertHasErrors(['Unauthorized.']);

    expect(Run::query()->whereOwnedBy($this->bob, 'owner')->count())->toBe(1);
});

it('refuses a reused idempotency key that names someone else\'s run', function (): void {
    app(Impex::class)->run('linear', [1], idempotencyKey: 'order-42', owners: [$this->ann]);

    $this->actingAs($this->bob)
        ->postJson('/impex/flows/linear/runs', ['arguments' => [1], 'idempotency_key' => 'order-42'])
        ->assertForbidden();

    $this->actingAs($this->bob);
    mcpTool(RunFlowTool::class, ['flow' => 'linear', 'arguments' => [1], 'idempotency_key' => 'order-42'])
        ->assertHasErrors(['Unauthorized.']);
});

it('scopes the ledger to the messages of runs the caller owns', function (): void {
    $run = app(Impex::class)->run('linear', [1], owners: [$this->ann]);
    $mine = app(Impex::class)->record(channel: 'sftp-drop', endpoint: 'sftp://partner.test/a.csv', body: 'a', runId: $run->id);
    $orphan = app(Impex::class)->record(channel: 'sftp-drop', endpoint: 'sftp://partner.test/b.csv', body: 'b');

    $this->actingAs($this->ann)->getJson('/impex/messages')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);

    $this->actingAs($this->ann)->getJson('/impex/messages/'.$mine->id)->assertOk();
    $this->actingAs($this->ann)->getJson('/impex/messages/'.$orphan->id)->assertForbidden();

    $this->actingAs($this->bob);
    mcpTool(ShowMessageTool::class, ['message' => $mine->id])->assertHasErrors(['Unauthorized.']);
});

it('uses a run policy swapped in the config, for runs and their owners', function (): void {
    useImpexPolicies([Run::class => ReadOnlyRunPolicy::class]);

    $run = app(Impex::class)->run('signal', [], owners: [$this->ann]);

    $this->actingAs($this->ann)->getJson('/impex/runs/'.$run->id)->assertOk();
    $this->actingAs($this->ann)->postJson('/impex/runs/'.$run->id.'/cancel')->assertForbidden();
    $this->actingAs($this->ann)->postJson('/impex/runs/'.$run->id.'/owners', [
        'owner_type' => $this->bob->getMorphClass(),
        'owner_id' => (string) $this->bob->getKey(),
        'role' => 'viewer',
    ])->assertForbidden();

    mcpTool(ShowRunTool::class, ['run' => $run->id])->assertOk();
    mcpTool(CancelRunTool::class, ['run' => $run->id])->assertHasErrors(['Unauthorized.']);
    mcpTool(AttachRunOwnerTool::class, [
        'run' => $run->id,
        'owner_type' => $this->bob->getMorphClass(),
        'owner_id' => (string) $this->bob->getKey(),
        'role' => 'viewer',
    ])->assertHasErrors(['Unauthorized.']);

    expect($run->owners()->count())->toBe(1);
});

it('checks a detached owner against an owner policy swapped in the config', function (): void {
    useImpexPolicies([RunOwner::class => KeepOwnersPolicy::class]);

    $run = app(Impex::class)->run('linear', [1], owners: [$this->ann]);
    $owner = $run->owners()->firstOrFail();

    $this->actingAs($this->ann)->deleteJson('/impex/runs/'.$run->id.'/owners/'.$owner->id)->assertForbidden();
    mcpTool(DetachRunOwnerTool::class, ['run' => $run->id, 'owner' => $owner->id])->assertHasErrors(['Unauthorized.']);

    expect($run->owners()->count())->toBe(1);
});

it('never asks a user to authorize the inbound channel endpoints', function (): void {
    config()->set('impex.channels', [
        'supplier-feed' => ['direction' => 'inbound', 'signing_secret' => 'shhh', 'signature_header' => 'X-Signature', 'flow' => 'linear'],
    ]);

    $body = (string) json_encode(['sku' => 'ABC-1']);

    $this->call('POST', '/impex/channels/supplier-feed', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, 'shhh'),
    ], content: $body)->assertStatus(202);
});

it('ships with authorization on', function (): void {
    /** @var array<string, mixed> $config */
    $config = require dirname(__DIR__, 2).'/config/impex.php';

    expect($config['authorization'])->toBeTrue();
});
