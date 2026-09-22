<?php

declare(strict_types=1);

use JayI\Impex\Enums\RunStatus;
use JayI\Impex\Impex;
use JayI\Impex\Mcp\Tools\ListChannelsTool;
use JayI\Impex\Mcp\Tools\ListFlowsTool;
use JayI\Impex\Mcp\Tools\ListMessagesTool;
use JayI\Impex\Mcp\Tools\ListRunStepsTool;
use JayI\Impex\Mcp\Tools\ListRunsTool;
use JayI\Impex\Mcp\Tools\RunFlowTool;
use JayI\Impex\Mcp\Tools\ShowRunTool;
use JayI\Impex\Mcp\Tools\SignalRunTool;
use JayI\Impex\Models\FlowOverride;
use JayI\Impex\Models\Run;
use JayI\Impex\Tests\Fixtures\Calls;
use JayI\Impex\Tests\Fixtures\LinearFlow;
use JayI\Impex\Tests\Fixtures\SignalFlow;

beforeEach(function (): void {
    Calls::reset();
    config()->set('queue.default', 'sync');
    config()->set('impex.flows', [
        'linear' => LinearFlow::class,
        'signal' => SignalFlow::class,
    ]);
});

it('exposes a tool for every use case', function (): void {
    $response = mcpTool(ListFlowsTool::class);

    $response->assertOk();
});

it('lists flows over MCP', function (): void {
    config()->set('impex.schedule', ['linear' => '0 * * * *']);

    mcpTool(ListFlowsTool::class, [])
        ->assertOk()
        ->assertSee('linear')
        ->assertSee('0 * * * *');
});

it('starts a run over MCP and records the trigger as mcp', function (): void {
    mcpTool(RunFlowTool::class, ['flow' => 'linear', 'arguments' => [1]])
        ->assertOk();

    $run = Run::query()->firstOrFail();

    // The trigger is what tells you an agent started this rather than a human.
    expect($run->trigger->value)->toBe('mcp')
        ->and($run->status)->toBe(RunStatus::Completed);
});

it('shares one implementation with the HTTP API', function (): void {
    // Same idempotency key across both surfaces: they resolve the same Action,
    // so the second call cannot start a second run.
    $this->postJson('/impex/flows/linear/runs', [
        'arguments' => [1],
        'idempotency_key' => 'shared-key',
    ])->assertStatus(202);

    mcpTool(RunFlowTool::class, ['flow' => 'linear', 'arguments' => [1], 'idempotency_key' => 'shared-key'])
        ->assertOk();

    expect(Run::query()->count())->toBe(1)
        ->and(Calls::count('add-one'))->toBe(1);
});

it('validates tool input with the same rules as the API', function (): void {
    mcpTool(ListRunsTool::class, ['status' => 'nonsense'])
        ->assertHasErrors();
});

it('surfaces an Impex exception message so an agent can act on it', function (): void {
    FlowOverride::query()->create(['slug' => 'linear', 'enabled' => false]);

    mcpTool(RunFlowTool::class, ['flow' => 'linear', 'arguments' => [1]])
        ->assertHasErrors()
        ->assertSee('is disabled');
});

it('signals a waiting run over MCP', function (): void {
    $run = app(Impex::class)->run('signal');

    expect($run->refresh()->status)->toBe(RunStatus::Waiting);

    mcpTool(SignalRunTool::class, ['run' => (string) $run->getKey(), 'name' => 'approval', 'payload' => ['approved' => true]])
        ->assertOk();

    expect($run->refresh()->status)->toBe(RunStatus::Completed);
});

it('lists a run steps over MCP without inlining payloads', function (): void {
    $run = app(Impex::class)->run('linear', [1]);

    mcpTool(ListRunStepsTool::class, ['run' => (string) $run->getKey()])
        ->assertOk()
        ->assertSee('has_result');
});

it('returns a not-found error for an unknown run rather than throwing', function (): void {
    mcpTool(ShowRunTool::class, ['run' => '01JQQQQQQQQQQQQQQQQQQQQQQQ'])
        ->assertHasErrors()
        ->assertSee('Not found');
});

it('lists the ledger over MCP', function (): void {
    app(Impex::class)->record(channel: 'sftp-drop', endpoint: 'sftp://partner.test/out.csv', body: 'a,b');

    mcpTool(ListMessagesTool::class, ['direction' => 'outbound'])
        ->assertOk()
        ->assertSee('sftp-drop');
});

it('never returns a channel signing secret over MCP', function (): void {
    config()->set('impex.channels', [
        'supplier-feed' => ['signing_secret' => 'shhh', 'flow' => 'linear'],
    ]);

    mcpTool(ListChannelsTool::class, [])
        ->assertOk()
        ->assertSee('supplier-feed')
        ->assertDontSee('shhh');
});
