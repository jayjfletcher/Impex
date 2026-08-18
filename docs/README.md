# Impex documentation

`jayi/impex` — a Laravel workflow engine and data-flow ledger, built for Vapor.

## Guides

| | |
|---|---|
| [Installation](01-installation.md) | Install, publish, schedule, and the Vapor checklist |
| [Flows and actions](02-flows.md) | Writing a workflow, the full DSL reference |
| [Determinism](03-determinism.md) | The replay contract, and what breaks it |
| [Compensation](04-compensation.md) | Rollback, policies, and what it cannot undo |
| [Signals and timers](05-signals-timers.md) | Waiting for humans and for the clock |
| [Scale](06-scale.md) | `fanOut`, `batch`, and resumable actions |
| [The ledger](07-ledger.md) | Inbound channels, outbound recording |
| [Ownership](08-ownership.md) | Polymorphic owners and scoping |
| [HTTP API](09-api.md) | Every endpoint, with requests and responses |
| [MCP](10-mcp.md) | Every tool, and how parity is enforced |
| [Dashboard](11-dashboard.md) | Mounting it, and the four auth modes |
| [Extending](12-extending.md) | Registering flows from a package, contracts, events |
| [Configuration](13-configuration.md) | Every config key |
| [Schema](14-schema.md) | Every table and column |
| [Testing](15-testing.md) | Testing flows in a host application |
| [Child workflows](16-children.md) | Running a flow from a flow, and close policies |
| [Versioning](17-versioning.md) | Branching old runs down old code, and deadlines |

## The shape of it in one page

```php
use JayI\Impex\Flows\Flow;

final class ExtractProductsFlow extends Flow
{
    public function handle(string $query, int $limit = 50): array
    {
        $hits = $this->action(SearchProducts::class, $query, $limit)->run();

        [$pricing, $inventory] = $this->parallel()
            ->action(FetchPricing::class, $hits)
            ->action(FetchInventory::class, $hits)
            ->run();

        $this->action(WriteToPim::class, $hits, $pricing, $inventory)
            ->compensateWith(RollbackPimWrite::class, $hits)
            ->run();

        return ['products' => count($hits)];
    }
}
```

```php
Impex::run('extract-products', ['drill bits', 50], idempotencyKey: $requestId);
```

Four things are true of that flow and worth holding onto:

1. **`handle()` runs many times.** It is replayed from the top on every drive.
   Each action runs once. See [Determinism](03-determinism.md).
2. **Nothing runs inline.** `Impex::run()` returns a pending run; the work is
   queued.
3. **No invocation is long-lived.** One step per job, so a run may live for days
   while no single execution approaches the platform's ceiling.
4. **Every step is recorded**, so the run survives worker restarts and
   at-least-once queue delivery without repeating side effects.
