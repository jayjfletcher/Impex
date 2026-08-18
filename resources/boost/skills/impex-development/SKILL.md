---
name: impex-development
description: >
  Build and operate workflows with the jayi/impex package in Laravel
  applications: writing flows and actions, making them safe to replay, keeping
  them inside serverless execution limits, and triggering them from the API,
  console, or scheduler.
license: MIT
metadata:
  author: Jay Fletcher
---

# Impex

Use this skill when a Laravel application runs multi-step work with `jayi/impex`
— writing a flow, adding an action, making long or large work survive a
serverless timeout, or tracking data crossing the application boundary.

## Primary Goal

- express the work as a deterministic flow whose every step is recorded, so a
  run survives worker restarts and at-least-once queue delivery without
  repeating side effects

## Workflow

### 1. Confirm the package is wired

- `jayi/impex` is in `composer.json`
- migrations are published: `php artisan vendor:publish --tag=impex-migrations`
- **`impex:tick` is on the schedule.** Without it, any run that sleeps or waits
  on a signal past the queue's delay ceiling never wakes. This is not optional.
- `impex.cache.store` points at a store that supports atomic locks and is shared
  across workers — Redis or DynamoDB on Vapor, never `array` or a per-instance
  store, because Lambda shares no memory between invocations

### 2. Write the flow

A flow is a class extending `JayI\Impex\Flows\Flow` with a public `handle()`.
Register it in `config/impex.php` under `flows`, keyed by slug.

```php
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

`handle()` is **re-executed from the top on every resume**. Each DSL call is
keyed by its position in the replay, so the method must be deterministic: same
inputs, same sequence of calls, every time.

### 3. Write the actions

An action is any container-resolvable class with a public `execute()`. It has no
base class to extend and no interface to implement.

```php
final class FetchPricing
{
    public function __construct(private readonly PricingClient $client) {}

    /**
     * @param  array<int, array{sku: string}>  $hits
     * @return array<string, float>
     */
    public function execute(array $hits): array
    {
        return $this->client->quote(array_column($hits, 'sku'));
    }
}
```

Arguments and return values must be JSON-serializable — plain arrays and
scalars. Anything above `impex.artifacts.inline_threshold` is written to the
artifact disk automatically; you do not need to handle that.

### 4. Make it safe to replay

Anything that cannot be recomputed identically must be wrapped:

```php
$stamp = $this->sideEffect('started-at', fn () => now()->toIso8601String());
$batch = $this->sideEffect('batch-id', fn () => (string) Str::ulid());
```

Read `references/determinism.md` before writing a flow that branches on
anything other than a recorded step result.

### 5. Keep it inside the execution limits

Two different ceilings, two different tools:

- **A single action that may run long** — extend `ResumableAction`, check
  `shouldYield()` at a checkpoint, and `return $this->yieldTo($cursor)`. The
  engine stores the cursor, releases the lease, and re-dispatches the same step.
- **Work spread over many items** — `fanOut()` up to `impex.limits.fan_out_max`
  (default 100), `batch()` above it. Replay is O(history) per drive, so
  per-item steps do not scale.
- **A different workflow entirely** — `child()`. The child is a run in its own
  right with its own compensation, and the parent parks on it.
- **Work that may hang rather than fail** — a deadline. `expiresAt()` on a step,
  `expiresAt:` on a run, or defaults in `impex.deadlines`. Enforced by
  `impex:tick`, because a step inside an upstream call cannot check a clock.

Read `references/serverless.md` before writing anything whose size is not known
in advance.

### 6. Record what crosses the boundary

Inbound traffic becomes a channel in `impex.channels`; outbound goes through
`Impex::http($channel, $runId, $stepId)` so every call lands in the ledger with
the run and step that made it. Name only the headers worth storing —
`store_headers` exists because webhook headers routinely carry credentials.

### 7. Trigger it

```php
Impex::run('extract-products', ['drill bits', 50], idempotencyKey: $requestId);
```

```
php artisan impex:run extract-products --argument="drill bits" --argument=50
```

Or put a cron expression in `impex.schedule` keyed by slug. A row in
`impex_flows` overrides that schedule and can disable the flow without a deploy.

Over HTTP, `POST impex/flows/{flow}/runs` answers `202` with the run. Over MCP,
`run-flow` does the same — both call one Action. Add authentication middleware
to `impex.routes.middleware` and `impex.mcp.web.middleware` before exposing
either: they trigger and cancel workflows and read every recorded payload.

### 8. Test it

The package ships assertions: `JayI\Impex\Testing\Flows`. `Flows::run()` drives
a run to completion in-process with no worker; `Flows::travelTo()` moves the
clock and runs the sweep, which is how you test a timeout, a sleep, or a
deadline. Write `Flows::redeliverSteps()` for any flow touching a
non-idempotent upstream — that is the test that at-least-once delivery does not
repeat a side effect.

### 9. Watch it

Enable `impex.ui` behind admin middleware for the dashboard, or use the
`list-runs` / `show-run` tools. Two independent layers: `impex.ui.middleware`
decides who may load the dashboard, `impex.routes.middleware` decides who may
call the API. `impex.ui.auth.mode` decides how the dashboard authenticates to
that API — `session`, `token`, `oauth` (PKCE, for Passport), or `custom`. A run at `waiting` is blocked on a signal or a
timer, not stuck. A failed run may have compensated — check the steps with
phase `compensation` to see what was rolled back.

## Rules, References, and Templates

Read before executing:

- `references/determinism.md` — the replay contract and what breaks it
- `references/serverless.md` — timeouts, payload limits, long waits, resume

The package's own `docs/` directory carries the full reference: the DSL
(`02-flows.md`), compensation (`04-compensation.md`), scale (`06-scale.md`),
the ledger (`07-ledger.md`), the API (`09-api.md`), MCP (`10-mcp.md`), and
every config key (`13-configuration.md`).

## Examples

- a supplier webhook lands on an inbound channel, which records the payload as a
  message and starts a flow that enriches ~40 SKUs with `fanOut()` and writes
  them to the PIM with a registered rollback
- a nightly catalogue sweep uses `batch()` over a million-row cursor whose seeder
  extends `ResumableAction` and checkpoints every few thousand rows, finishing
  across dozens of invocations without any one exceeding the platform ceiling
- a purchase-order flow performs two steps, then `awaitSignal('approval')` with a
  three-day timeout; the run sits at `waiting` costing nothing until a human
  approves it or `impex:tick` fires the timeout

## Anti-patterns

- **do not** call `now()`, `rand()`, `Str::ulid()`, or an unrecorded query
  directly in `handle()` — wrap them in `sideEffect()` or the run will diverge
  on resume
- **do not** catch `JayI\Impex\Runtime\Suspended` in flow code; it is the
  engine's control flow, and catching it corrupts the run
- **do not** put business logic in `handle()` — it belongs in an action, because
  `handle()` re-runs on every drive while an action runs once
- **do not** pass Eloquent models or closures as action arguments; pass
  identifiers and re-resolve inside the action
- **do not** use a closure for `compensateWith()` — the rollback is captured
  when the forward step is recorded, so it must be a class name
- **do not** assume an action runs exactly once because the step is leased: a
  lapsed lease is reclaimable by design, so any action touching a
  non-idempotent upstream still needs its own idempotency key
- **do not** `fanOut()` over an unbounded collection — use `batch()`
- **do not** deploy a changed `handle()` while runs of that flow are live
  without versioning it: declare `public const VERSION` and branch on
  `$this->version()`, or drain the active runs first. Otherwise the replay
  diverges and the run fails with `HistoryMismatchException`
- **do not** reach for `runSync()` or `wait: true` for anything but short flows
  and tests — the gateway times out long before a real flow finishes
- **do not** use `compensateInParallel()` unless the group's steps are genuinely
  independent; reverse order exists because rollbacks usually depend on it
