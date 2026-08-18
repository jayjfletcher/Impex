# MCP

The same operations over MCP as over HTTP. Both surfaces call one Action, so
they cannot drift.

```php
// config/impex.php — both transports ship disabled
'mcp' => [
    'web' => [
        'enabled' => true,
        'route' => 'mcp/impex',
        'middleware' => ['auth:api'],
    ],
    'local' => [
        'enabled' => true,
        'handle' => 'impex',
    ],
],
```

**Add auth middleware to the web transport before enabling it.** The server
triggers and cancels workflows and reads every recorded payload.

## Tools

| Tool | Action |
|---|---|
| `list-flows` | Registered flows, with enabled state and schedule |
| `run-flow` | Start a run. Asynchronous. |
| `list-runs` | Filter by status, flow, trigger, owner, tag, dates. Cursor paginated. |
| `show-run` | One run with owners and step history |
| `cancel-run` | Cancel an unfinished run |
| `retry-run` | Re-queue a drive |
| `list-run-steps` | Recorded steps, optionally by phase |
| `signal-run` | Deliver a signal to a waiting run |
| `list-run-owners` | Models with a stake in a run |
| `attach-run-owner` | Give a model a stake |
| `detach-run-owner` | Remove one |
| `list-messages` | The data-flow ledger |
| `show-message` | One message with headers and body preview |
| `list-channels` | Inbound channels. Never returns signing secrets. |

## Server instructions

The server tells an agent the things it cannot infer from a schema:

> A run is one execution of a flow; its steps are the recorded history of what
> it did, in replay order. Starting a run is always asynchronous — `run-flow`
> returns a pending run and the work is queued, so poll `show-run` rather than
> expecting a result. A run with status "waiting" is blocked on a signal or a
> timer: `signal-run` releases it. A failed run may have rolled back:
> `list-run-steps` with phase "rollback" shows what was rolled back. The
> ledger records every payload that has crossed the application boundary in
> either direction, linked to the run and step that caused it. Payloads are
> never inlined in listings — large results live on an artifact disk and are
> referenced by id.

## Parity

An arch test compares `src/Actions/` against `src/Mcp/Requests/` and fails when
an Action has no tool:

```php
// tests/ArchTest.php
arch('every use case is reachable from both the HTTP API and MCP')
    ->expect(fn (): array => parityGaps())
    ->toBeEmpty();
```

Deliberate omissions go in a `MCP_EXCEPTIONS` map with a reason, so an omission
is a test failure but an exception is one line. Cortex, for comparison, keeps
its tool-description endpoints HTTP-only; Impex currently has no exceptions.

## Errors

An `ImpexException` surfaces its message rather than a generic failure, because
those messages carry guidance an agent can act on:

```
The flow [extract-products] is disabled. Re-enable it by removing or updating
its row in impex_flows.
```

A missing record returns `Not found.` rather than throwing.

## Testing tools

```php
use JayI\Impex\Mcp\ImpexServer;
use JayI\Impex\Mcp\Tools\RunFlowTool;

ImpexServer::tool(RunFlowTool::class, ['flow' => 'linear', 'arguments' => [1]])
    ->assertOk()
    ->assertSee('completed');
```
