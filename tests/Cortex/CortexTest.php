<?php

declare(strict_types=1);

use JayI\Cortex\Actions\CreateMcpInstructionVersionAction;
use JayI\Cortex\Actions\CreateToolDescriptionVersionAction;
use JayI\Cortex\Mcp\McpServerRegistry;
use JayI\Cortex\Tools\ToolRegistry;
use JayI\Impex\Cortex\CortexIntegration;
use JayI\Impex\Mcp\ImpexServer;
use JayI\Impex\Mcp\Tools\ListRunsTool;
use Laravel\Ai\Contracts\Tool as AgentTool;
use Laravel\Ai\Tools\Request;
use Laravel\Mcp\Server\Transport\FakeTransporter;

it('registers the MCP server with Cortex', function (): void {
    $servers = app(McpServerRegistry::class);

    expect($servers->has('impex'))->toBeTrue()
        ->and($servers->get('impex'))->toBe(ImpexServer::class)
        ->and($servers->defaultInstructions('impex'))->toStartWith('Track and control Impex workflows');
});

it('offers every Impex tool to Cortex agents under its own name', function (): void {
    $tools = app(ToolRegistry::class);

    $names = array_map(fn (string $class): string => app($class)->name(), ImpexServer::TOOLS);

    expect(ImpexServer::TOOLS)->toHaveCount(14)
        ->and(array_diff($names, $tools->names()))->toBe([])
        ->and($tools->get('list-runs-tool'))->toBeInstanceOf(AgentTool::class);
});

it('offers only the tools listed in config', function (): void {
    config()->set('impex.cortex.tools', ['list-runs-tool', 'show-run-tool']);
    app()->forgetInstance(ToolRegistry::class);

    $tools = app(ToolRegistry::class);

    expect($tools->has('list-runs-tool'))->toBeTrue()
        ->and($tools->has('show-run-tool'))->toBeTrue()
        ->and($tools->has('run-flow-tool'))->toBeFalse();
});

it('serves the instructions published in Cortex', function (): void {
    app(CreateMcpInstructionVersionAction::class)->execute('impex', ['content' => 'Only retry failed runs.', 'publish' => true]);

    expect((new ImpexServer(new FakeTransporter))->createContext()->instructions)->toBe('Only retry failed runs.');
});

it('serves tool descriptions published in Cortex, to MCP clients and agents alike', function (): void {
    app(CreateToolDescriptionVersionAction::class)->execute('list-runs-tool', ['content' => 'List the runs of our nightly syncs.', 'publish' => true]);

    expect(app(ListRunsTool::class)->description())->toBe('List the runs of our nightly syncs.')
        ->and(app(ToolRegistry::class)->get('list-runs-tool')->description())->toBe('List the runs of our nightly syncs.');
});

it('lets an agent call an Impex tool with its arguments', function (): void {
    // The tool takes its own request class; without the arguments it would
    // fail validation on `run` rather than look the run up.
    $result = (string) app(ToolRegistry::class)->get('show-run-tool')->handle(new Request(['run' => '01JAAAAAAAAAAAAAAAAAAAAAAA']));

    expect($result)->toContain('Not found.');
});

it('stays out of Cortex when turned off', function (): void {
    $integration = app(CortexIntegration::class);

    expect($integration->active())->toBeTrue();

    config()->set('impex.cortex.enabled', false);

    expect($integration->active())->toBeFalse()
        ->and($integration->instructions())->toBeNull()
        ->and($integration->description('list-runs-tool'))->toBeNull();
});
