<?php

declare(strict_types=1);

namespace JayI\Impex\Mcp;

use JayI\Impex\Cortex\CortexIntegration;
use JayI\Impex\Mcp\Tools\AttachRunOwnerTool;
use JayI\Impex\Mcp\Tools\CancelRunTool;
use JayI\Impex\Mcp\Tools\DetachRunOwnerTool;
use JayI\Impex\Mcp\Tools\ListChannelsTool;
use JayI\Impex\Mcp\Tools\ListFlowsTool;
use JayI\Impex\Mcp\Tools\ListMessagesTool;
use JayI\Impex\Mcp\Tools\ListRunOwnersTool;
use JayI\Impex\Mcp\Tools\ListRunStepsTool;
use JayI\Impex\Mcp\Tools\ListRunsTool;
use JayI\Impex\Mcp\Tools\RetryRunTool;
use JayI\Impex\Mcp\Tools\RunFlowTool;
use JayI\Impex\Mcp\Tools\ShowMessageTool;
use JayI\Impex\Mcp\Tools\ShowRunTool;
use JayI\Impex\Mcp\Tools\SignalRunTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolSearch;

#[Name('Impex')]
#[Version('1.0.0')]
#[Instructions(
    'Track and control Impex workflows, and inspect the flow of data in and out of this application. '.
    'A run is one execution of a flow; its steps are the recorded history of what it did, in replay order. '.
    'Starting a run is always asynchronous — run-flow-tool returns a pending run and the work is queued, so poll '.
    'show-run-tool rather than expecting a result. A run with status "waiting" is blocked on a signal or a timer: '.
    'signal-run-tool releases it. A failed run may have rolled back: list-run-steps-tool with phase "rollback" '.
    'shows what was rolled back. The ledger (list-messages-tool) records every payload that has crossed the '.
    'application boundary in either direction, linked to the run and step that caused it. Payloads are never '.
    'inlined in listings — large results live on an artifact disk and are referenced by id.',
)]
final class ImpexServer extends Server
{
    /**
     * Every tool the server offers, behind ToolSearch. Also registered with
     * Cortex when it is installed.
     *
     * @var array<int, class-string<Tool>>
     */
    public const array TOOLS = [
        // Flows
        ListFlowsTool::class,
        RunFlowTool::class,

        // Runs
        ListRunsTool::class,
        ShowRunTool::class,
        CancelRunTool::class,
        RetryRunTool::class,

        // Run detail
        ListRunStepsTool::class,
        SignalRunTool::class,

        // Ownership
        ListRunOwnersTool::class,
        AttachRunOwnerTool::class,
        DetachRunOwnerTool::class,

        // Ledger
        ListMessagesTool::class,
        ShowMessageTool::class,
        ListChannelsTool::class,
    ];

    /**
     * @var array<class-string<ToolSearch>, array<int, class-string<Tool>|Tool>>
     */
    protected array $tools = [
        ToolSearch::class => self::TOOLS,
    ];

    /**
     * Serve Cortex's published instructions override, when Cortex is
     * installed and one is published, in place of the ones declared above.
     */
    public function createContext(): ServerContext
    {
        $context = parent::createContext();
        $override = app(CortexIntegration::class)->instructions();

        if ($override !== null) {
            $context->instructions = $override;
        }

        return $context;
    }
}
