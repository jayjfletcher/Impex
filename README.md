# Impex

Workflow engine and data-flow ledger for Laravel.

Impex runs multi-step work as deterministic, replayable flows with automatic
rollback, and records every payload that crosses the application boundary so you
can see the flow of data in and out. It is designed for a runtime with a hard
execution ceiling, a small queue message, and no local disk.

> **Status: complete through the dashboard.** Everything below is built and
> tested — engine, ledger, HTTP API, MCP server, and UI.

## Installation

```bash
composer require jayi/impex
php artisan vendor:publish --tag=impex-migrations
php artisan vendor:publish --tag=impex-config
php artisan migrate
```

Put the sweep on the schedule. **Without it, any run that sleeps or waits on a
signal past the queue's delay ceiling never wakes:**

```php
$schedule->command('impex:tick')->everyMinute();
```

The package registers this itself when `impex.timers.enabled` is not `false`.

On Vapor/Lambda, point the artifact disk at S3 and the lock store at Redis or DynamoDB:

```php
'artifacts' => ['disk' => 's3'],
'cache' => ['store' => 'dynamodb'],
```

## Writing a flow

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
            ->undoWith(RollbackPimWrite::class, $hits)
            ->run();

        return ['products' => count($hits)];
    }
}
```

Register it:

```php
// config/impex.php
'flows' => [
    'extract-products' => \App\Flows\ExtractProductsFlow::class,
],
```

An action is any container-resolvable class with a public `execute()`:

```php
final class FetchPricing
{
    public function __construct(private readonly PricingClient $client) {}

    public function execute(array $hits): array
    {
        return $this->client->quote(array_column($hits, 'sku'));
    }
}
```

## Running one

```php
use JayI\Impex\Facades\Impex;

$run = Impex::run('extract-products', ['drill bits', 50], idempotencyKey: $requestId);

$run->status;              // RunStatus::Pending — nothing runs inline
Impex::result($run);       // the flow's return value, once completed
```

```bash
php artisan impex:run extract-products --argument="drill bits" --argument=50
```

Or schedule it, by slug:

```php
'schedule' => ['extract-products' => '0 * * * *'],
```

## How replay works

`handle()` is re-executed from the top on every resume. Each DSL call is keyed
by its position in the replay, and a step already recorded returns its stored
result rather than running again. So `handle()` must be deterministic — wrap
anything you cannot recompute:

```php
$stamp = $this->sideEffect('started-at', fn () => now()->toIso8601String());
```

A divergence fails the run with `HistoryMismatchException` rather than
corrupting it. See `resources/boost/skills/impex-development/references/determinism.md`.

## Long work

A Lambda timeout cannot be caught, so an action that may run long stops *before*
the ceiling and checkpoints:

```php
use JayI\Impex\Flows\ResumableAction;

final class SeedProducts extends ResumableAction
{
    public function execute(string $query): mixed
    {
        $cursor = $this->cursor();

        foreach ($this->pages($query, $cursor) as $page) {
            $this->seed($page->items());
            $cursor = $page->lastKey();

            if ($this->shouldYield()) {
                return $this->yieldTo($cursor);
            }
        }

        return ['seeded' => $this->total];
    }
}
```

The engine stores the cursor, releases the lease, and re-dispatches the same
step — same sequence, so the replay never notices. Yielding the same cursor
twice, or exceeding `max_resumptions`, fails loudly rather than looping.

## Fanning out

Two primitives, because they scale differently. Replay is O(history) per drive,
so N per-item steps cost roughly N²/2 step-row reads across a run:

```php
// Up to impex.limits.fan_out_max (default 100). One recorded step per item, so
// items retry and roll back individually and results come back in order.
$products = $this->fanOut($hits, fn (array $hit) => $this->action(FetchProduct::class, $hit['sku']))
    ->keyBy(fn (array $hit): string => $hit['sku'])   // optional; tolerates re-ordering
    ->run();
```

```php
// Unbounded. ONE step in the replay history whatever the item count, with
// per-item state in impex_batch_items — a table the replay never reads.
$summary = $this->batch(ProductSearchSource::class, $query)
    ->using(EnrichProduct::class)
    ->chunk(500)
    ->allowFailures(0.02)
    ->run();
// ['batch_id' => '01JQ…', 'total' => 1000000, 'succeeded' => 999812, 'failed' => 188]

foreach (Impex::batchItems($summary['batch_id']) as $item) {
    // streamed, never held in memory
}
```

A batch source hands back a cursor rather than a generator, because generators
cannot be serialized across invocations and seeding a large source will span
more of them than any one may live for:

```php
final class ProductSearchSource implements BatchSource
{
    public function __construct(private readonly string $query) {}

    public function chunk(?string $cursor, int $size): BatchChunk
    {
        $page = Product::search($this->query)->after($cursor)->take($size)->get();

        if ($page->isEmpty()) {
            return BatchChunk::last();
        }

        return BatchChunk::of(
            $page->map(fn (Product $p) => new BatchChunkItem($p->sku, ['sku' => $p->sku]))->all(),
            $page->last()->sku,
        );
    }
}
```

`fanOut` over more than the cap raises `FanOutTooLargeException` pointing at
`batch()`. What `batch` costs you: no per-item rollback, no positional
results, no per-item signals.

## The ledger

Every payload crossing the boundary is recorded, in both directions.

Inbound, as a named channel:

```php
'channels' => [
    'supplier-feed' => [
        'signing_secret' => env('IMPEX_SUPPLIER_SECRET'),
        'signature_header' => 'X-Signature',
        'idempotency_header' => 'X-Request-Id',
        'flow' => 'extract-products',
        'store_headers' => ['content-type', 'x-request-id'],
    ],
],
```

`POST /impex/channels/supplier-feed` validates the signature, records the
request, responds `202`, and queues the bound flow. A request with a bad
signature is still recorded — it is evidence of what an upstream sent — but
starts no run. Only the headers you name are stored, because webhook headers
routinely carry credentials.

Outbound, as the mirror:

```php
Impex::http('vendor-api', $runId, $stepId)->post('https://vendor.test/quote', $payload);

Impex::record(channel: 'sftp-drop', endpoint: 'sftp://partner.test/out.csv', body: $csv);
```

## HTTP API

```
GET    impex/flows                        POST   impex/flows/{flow}/runs
GET    impex/runs                         GET    impex/runs/{run}
POST   impex/runs/{run}/cancel            POST   impex/runs/{run}/retry
GET    impex/runs/{run}/steps             POST   impex/runs/{run}/signals
GET    impex/runs/{run}/owners            POST   impex/runs/{run}/owners
DELETE impex/runs/{run}/owners/{owner}
GET    impex/messages                     GET    impex/messages/{message}
GET    impex/channels                     POST   impex/channels/{channel}
```

Run listing filters on `status`, `flow`, `trigger`, `owner_type`+`owner_id`,
`tag[key]=value`, `since`, `until`, and `parent`, with cursor pagination.
Triggering answers `202` with the run — never inline execution.

Every endpoint is one line: validation rules come from an Action's static
`rules()`, and the request's `persist()` calls that same Action. The MCP surface
calls the same Actions, so the two cannot drift.

**Add authentication middleware before exposing any of this.** The default
`impex.routes.middleware` is `['api']` — these endpoints trigger and cancel
workflows and read every payload that has crossed the boundary.

With `impex.authorization` on (the default), every API call and MCP tool acts as the
authenticated user: they list only the runs they own, own the runs they start,
and each call is checked against the model policies in `impex.policies`. By
default a run's owners may do anything with it and its steps, owners, signals
and messages follow it. Replace a policy by pointing its model at your own
class. Set it to `false` only for a trusted operator surface. The inbound
channel endpoints stay signature-authenticated, never user-authorized. See [Ownership](docs/08-ownership.md#authorizing-the-api).

## MCP

The same operations over MCP as over HTTP. Both surfaces call one Action, so
they cannot drift — an arch test fails if an Action gains an HTTP route without
a tool.

```php
// config/impex.php — both transports ship disabled
'mcp' => [
    'web' => ['enabled' => true, 'route' => 'mcp/impex', 'middleware' => ['auth:sanctum']],
    'local' => ['enabled' => true, 'handle' => 'impex'],
],
```

The server lists two entry points, `search_tools` and `execute_tools`, with
fourteen tools behind them: `list-flows-tool`, `run-flow-tool`, `list-runs-tool`,
`show-run-tool`, `cancel-run-tool`, `retry-run-tool`, `list-run-steps-tool`,
`signal-run-tool`, `list-run-owners-tool`, `attach-run-owner-tool`,
`detach-run-owner-tool`, `list-messages-tool`, `show-message-tool` and
`list-channels-tool`.

The server's instructions tell an agent the things it cannot infer from the
schema: that `run-flow-tool` is asynchronous and `show-run-tool` must be polled, that a
`waiting` run is blocked on a signal, and that payloads are never inlined in
listings.

## Cortex

When [`jayi/cortex`](https://github.com/jayjfletcher/cortex) is installed, Impex connects its MCP server to it. Nothing needs registering in your app.

- **Agents can run workflows.** Every Impex MCP tool joins Cortex's tool registry under its own name (`list-runs-tool`, `run-flow-tool`, `signal-run-tool`, ...), so an agent can start, inspect and control runs.
- **Instructions and descriptions can change without a deploy.** The server is registered with Cortex as `impex`, so its instructions get Cortex's versioned, publishable overrides, and so does each tool's description. Published overrides are served both to MCP clients (for example at `/mcp/impex`) and to agents.

```php
// config/impex.php
'cortex' => [
    'enabled' => true,      // false leaves Cortex alone
    'server' => 'impex',    // the server's name in Cortex
    'tools' => null,        // null for every tool, or a list of names
],
```

About how it works:

- **Optional:** Cortex is not a dependency. Without it nothing Cortex-related loads.
- **Lazy:** registration happens the first time Cortex's registries are used.
- **Your config wins:** a name already registered with Cortex, for example in your own `config/cortex.php`, is left alone.
- **Tool list from the server:** the tools come from `ImpexServer::TOOLS`, which is also the catalog the server serves, so a tool added to Impex reaches Cortex without any change in your app.

## Dashboard

Impex renders its dashboard through [Atrium](https://github.com/jayjfletcher/Atrium), which it requires. Define Atrium's gate and Impex appears in the sidebar:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewAtrium', fn ($user) => $user->is_admin);
```

Screens: runs filterable by status, flow, trigger, owner and tag; run detail with the step timeline, the rollback path, and a signal form for a waiting run; the message ledger in both directions; the flow catalogue with a trigger form; and channel health.

Impex also contributes three dashboard widgets — run status counts, recent failures and message volume. They are **offered** in Atrium's widget picker; none is placed on anyone's dashboard automatically.

Atrium owns the path, the middleware and the authorization gate, so the only setting here is the switch:

```php
// config/impex.php
'ui' => ['enabled' => true],
```

Set it to `false` to keep the JSON API without adding Impex to the dashboard.

> **Gate this carefully.** The dashboard renders every payload that has crossed your application boundary.

## Child workflows

```php
$billing = $this->child('provision-billing', $customerId)
    ->closePolicy(ChildClosePolicy::Cancel)
    ->run();
```

A child is a run in its own right — own history, own rollback, own row —
linked by `parent_run_id`. The parent parks on it like any other step. A failed
child unwinds the parent, after rolling back itself.

## Versioning

```php
final class ExtractProductsFlow extends Flow
{
    public const VERSION = 'v2';

    public function handle(string $query): array
    {
        if ($this->version() === 'v1') {
            // the path runs already in flight began with
        }
    }
}
```

Every run records the version it started under, so one class serves both the
runs in flight and the new ones. Repointing a slug at a *different* class is
refused on the next drive rather than replayed into the wrong code.

## Deadlines

```php
Impex::run('extract-products', [$query], expiresAt: 3600);

$this->action(FetchPricing::class, $skus)->expiresAt(now()->addMinutes(10))->run();
```

Enforced by `impex:tick`, not in-process — a step that has handed control to an
upstream call cannot check a clock. A lease stops a *killed* invocation wedging
a run; a deadline stops a *hung* one running forever.

## Signals

```php
$decision = $this->signal('approval')
    ->timeoutAfter(now()->addDays(3))
    ->default(['approved' => false])
    ->wait();
```

```php
Impex::signal($run, 'approval', ['approved' => true], idempotencyKey: $eventId);
Impex::signalIfRunning($run, 'approval');   // false if the run already finished
```

```bash
php artisan impex:signal 01JQ7X… approval --payload='{"approved":true}'
```

A signal is accepted by any unfinished run and is **held** if it arrives before
the flow reaches its wait, so there is no race with the run's own progress.
Signalling a finished run throws rather than writing a row nothing will consume.
A timed out wait is recorded as skipped, so `null` from a real payload stays
distinguishable from nobody answering.

Find the run with `Run::query()->signalable()` — `running()` would miss exactly
the runs parked waiting for one.

## Long waits

```php
$approval = $this->awaitSignal('approval', timeout: now()->addDays(3));

$this->sleepUntil(now()->addWeek());
```

Both become rows in `impex_timers`, swept by `impex:tick`, because SQS caps
message delay at 15 minutes. A waiting run costs nothing while it waits.

Deliver a signal from anywhere:

```php
Impex::signal($run, 'approval', ['approved' => true], idempotencyKey: $eventId);
```

## Ownership

No user, team, or customer tables ship with this package — the host app decides
what those are. Any model can own a run, in any role:

```php
Impex::run('extract-products', [$query], owners: [
    'customer' => $customer,
    'team' => $team,
    'user' => $user,
]);

Run::query()->whereOwnedBy($customer)->active()->get();
Run::query()->whereOwnedByAny([$team, $user])->get();
```

## Events

Impex fires three families of events:

- **Engine events** describe what a run does as it executes: `RunStarted`, `RunCompleted`, `RunFailed`, `StepCompleted`, `StepFailed` and `MessageRecorded`.
- **Model events** fire for every Eloquent lifecycle hook of every Impex model, one class per hook.
- **Action events** are a start and a finish event for every action, whether it runs from code, the HTTP API, MCP, a Cortex agent or the dashboard.

### Model events

Every model fires `retrieved`, `creating`, `created`, `updating`, `updated`, `saving`, `saved`, `deleting`, `deleted` and `replicating`:

- **Models:** `Run`, `RunStep`, `RunOwner`, `Artifact`, `Batch`, `BatchItem`, `Message`, `Signal`, `Timer`, `FlowOverride`.
- **Naming:** they live in `JayI\Impex\Events\Model` and are named `{Model}{Hook}Event`, e.g. `RunCreatingEvent` or `RunStepCreatedEvent`.
- **Payload:** the model is a typed property (`$event->run`, `$event->runStep`, ...) and is also available as `$event->model()`, alongside `$event->hook()`.
- **Timing:** they fire synchronously, as Eloquent's own do. A `creating`, `updating`, `saving` or `deleting` listener that returns `false` stops the write.

Some engine writes are atomic query-builder updates, so that two workers can never both win. Eloquent does not turn those statements into model events. They are:

- claiming (leasing) a step or batch item, and releasing it
- failing steps whose deadline has passed
- firing due timers in bulk

Everything else, including run status changes and recording a step's outcome, goes through Eloquent and fires model events. For the moments the atomic updates cover, listen to the engine events instead.

### Action events

Every action dispatches two events:

- **Start:** `…ingActionEvent`, before any work. It carries the input.
- **Finish:** `…edActionEvent`, after the surrounding transaction commits and only on success. It carries the result.

An action that throws fires its start event only.

Starting a flow is named `FlowRunningActionEvent` / `FlowRanActionEvent`, so it does not clash with the engine's `RunStarted`. `FlowRan` marks the run being accepted. `RunStarted` marks it actually beginning, later, on the queue.

| Action | Start (carries) | Finish (carries) |
| --- | --- | --- |
| `AttachRunOwnerAction` | `RunOwnerAttachingActionEvent` (`run`, `data`) | `RunOwnerAttachedActionEvent` (`run`, `owner`) |
| `CancelRunAction` | `RunCancellingActionEvent` (`run`, `reason`) | `RunCancelledActionEvent` (`run`) |
| `DetachRunOwnerAction` | `RunOwnerDetachingActionEvent` (`run`, `owner`) | `RunOwnerDetachedActionEvent` (`run`, `ownerType`, `ownerId`, `role`) |
| `ListChannelsAction` | `ChannelsListingActionEvent` (none) | `ChannelsListedActionEvent` (`channels`) |
| `ListFlowsAction` | `FlowsListingActionEvent` (none) | `FlowsListedActionEvent` (`flows`) |
| `ListMessagesAction` | `MessagesListingActionEvent` (`filters`, `viewer`) | `MessagesListedActionEvent` (`messages`, `viewer`) |
| `ListRunOwnersAction` | `RunOwnersListingActionEvent` (`run`) | `RunOwnersListedActionEvent` (`run`, `owners`) |
| `ListRunStepsAction` | `RunStepsListingActionEvent` (`run`, `filters`) | `RunStepsListedActionEvent` (`run`, `steps`) |
| `ListRunsAction` | `RunsListingActionEvent` (`filters`, `viewer`) | `RunsListedActionEvent` (`runs`, `viewer`) |
| `RetryRunAction` | `RunRetryingActionEvent` (`run`) | `RunRetriedActionEvent` (`run`) |
| `RunFlowAction` | `FlowRunningActionEvent` (`slug`, `data`, `trigger`, `owner`) | `FlowRanActionEvent` (`run`) |
| `ShowMessageAction` | `MessageShowingActionEvent` (`message`) | `MessageShownActionEvent` (`message`) |
| `ShowRunAction` | `RunShowingActionEvent` (`run`) | `RunShownActionEvent` (`run`) |
| `SignalRunAction` | `RunSignallingActionEvent` (`run`, `data`) | `RunSignalledActionEvent` (`run`, `signal`) |

### Listening to a whole family

Listen to an interface in `JayI\Impex\Contracts` to receive every event of that family:

| Interface | Receives |
| --- | --- |
| `ModelLifecycleEvent` | every model event |
| `ActionStartingEvent` | every action start |
| `ActionFinishedEvent` | every action finish |

```php
Event::listen(ActionFinishedEvent::class, fn (ActionFinishedEvent $event) => Log::info(class_basename($event)));
```

## Vapor notes

| Constraint | Handling |
|---|---|
| 900s execution ceiling | step-per-invocation; long steps checkpoint and resume |
| 256KB message limit | jobs carry ULIDs only; payloads above 64KB go to the artifact disk |
| 15-minute delay cap | long waits are timer rows swept by `impex:tick` |
| at-least-once delivery | steps are leased before they execute; runs take an idempotency key |
| no local disk | artifacts live on a configured Flysystem disk |
| no shared memory | drives are serialised with a cache lock on a shared, lock-capable store |

## Commands

| | |
|---|---|
| `impex:tick` | fire due timers, reclaim lapsed leases, enforce deadlines |
| `impex:run {flow}` | start a run |
| `impex:signal {run} {name}` | deliver a signal to a run |
| `impex:prune` | prune expired runs, messages, and artifacts |

## Roadmap

- [x] Replay engine, rollback, signals, timers, artifact offload, resume
- [x] Flow registry with database overrides
- [x] `fanOut()` and `batch()`
- [x] Messages ledger, inbound channels, outbound recorder
- [x] Run owners and the HTTP API
- [x] MCP server with API parity
- [x] Dashboard

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE.md](LICENSE.md).
