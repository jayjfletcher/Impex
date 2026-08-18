# Flows and actions

## A flow

A flow is a class extending `JayI\Impex\Flows\Flow` with a public `handle()`.
Its signature is yours — whatever arguments the flow needs.

```php
namespace App\Flows;

use App\Flows\Actions\FetchInventory;
use App\Flows\Actions\FetchPricing;
use App\Flows\Actions\RollbackPimWrite;
use App\Flows\Actions\SearchProducts;
use App\Flows\Actions\WriteToPim;
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

Unkeyed entries derive a slug from the class name, so
`\App\Flows\ExtractProductsFlow::class` alone becomes `extract-products-flow`.
Prefer explicit slugs: the slug is a public identifier that appears in API
calls, in the database, and in your scheduler.

## An action

Any container-resolvable class with a public `execute()`. No base class, no
interface. Constructor injection works as it does anywhere.

```php
namespace App\Flows\Actions;

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

Arguments and return values must be **JSON-serializable** — plain arrays and
scalars. Pass identifiers, not Eloquent models:

```php
// Wrong: a serialized model is a snapshot that goes stale between drives.
$this->action(ShipOrder::class, $order)->run();

// Right: the action re-resolves it, so it always sees current state.
$this->action(ShipOrder::class, $order->id)->run();
```

Anything above `impex.artifacts.inline_threshold` is written to the artifact
disk automatically. You never handle that.

## The DSL

Everything below is available inside `handle()`.

### `action(string $class, mixed ...$arguments): ActionBuilder`

One recorded, retryable, reversible step.

```php
$result = $this->action(ChargeCard::class, $orderId, $amount)
    ->tries(3)                                     // attempts before terminal failure
    ->undoWith(RefundCard::class, $orderId)  // rollback, captured now
    ->expiresAt(now()->addMinutes(10))             // a deadline for this step
    ->continueOnFailure(['charged' => false])      // treat failure as this value
    ->run();
```

| Method | Effect |
|---|---|
| `tries(int)` | Attempts before the step fails terminally. Default 1. |
| `undoWith(string $class, ...$args)` | Rollback to run if a later step fails. Class, not closure — see [Rollback](04-rollback.md). |
| `expiresAt(DateTimeInterface)` | A deadline recorded on the step. |
| `continueOnFailure(mixed $fallback = null)` | A terminal failure returns `$fallback` instead of unwinding the run. |
| `run()` | Resolve: return the recorded result, or schedule and suspend. |
| `descriptor()` | The immutable description, for use inside `parallel()`. |

### `optionalAction(string $class, mixed ...$arguments): ActionBuilder`

An action whose failure should not unwind the run. Shorthand for
`action(...)->continueOnFailure()`.

```php
// A missing thumbnail is not worth rolling back an import for.
$thumbnail = $this->optionalAction(GenerateThumbnail::class, $sku)->run();   // null on failure
```

### `unit(): UnitBuilder`

Groups steps under one rollback policy. The forward path is unchanged — each
step is recorded exactly as `action()` would record it. What changes is the
rollback.

```php
$this->unit()
    ->onRollbackFailure(RollbackFailure::Continue)
    ->rollbackTogether()
    ->step(ChargeCard::class, $orderId)->undoWith(RefundCard::class, $orderId)
    ->step(ReserveStock::class, $orderId)->undoWith(ReleaseStock::class, $orderId)
    ->step(ShipOrder::class, $orderId)
    ->run();
```

| Method | Effect |
|---|---|
| `onRollbackFailure(RollbackFailure)` | `Stop` (default) halts the rollback; `Continue` pushes through. |
| `rollbackTogether()` | Roll the group back all at once instead of in reverse order. Only safe when the steps are independent. |
| `step(string $class, ...$args)` | Add a step. |
| `undoWith(string $class, ...$args)` | Rollback for the step just added. |
| `tries(int)` | Attempts for the step just added. |

### `child(string $flow, mixed ...$arguments): ChildBuilder`

Run another flow as a child. See [Child workflows](16-children.md).

```php
$result = $this->child('provision-billing', $customerId)
    ->closePolicy(ChildClosePolicy::Cancel)
    ->run();
```

### `version(): ?string`

The version this run started under, for branching old runs down old code. See
[Versioning](17-versioning.md).

### `parallel(): ParallelBuilder`

Several actions concurrently, joined on all of them. Results come back in
declaration order.

```php
[$images, $pricing, $inventory] = $this->parallel()
    ->action(DownloadImages::class, $skus)
    ->action(FetchPricing::class, $skus)
    ->action(FetchInventory::class, $skus)
    ->failurePolicy(ParallelFailure::SettleAll)
    ->run();
```

A branch that needs its own retry or rollback policy is added pre-built:

```php
$this->parallel()
    ->add($this->action(ChargeCard::class, $id)->undoWith(RefundCard::class, $id))
    ->add($this->action(ReserveStock::class, $id)->tries(3))
    ->run();
```

| Policy | Behaviour |
|---|---|
| `ParallelFailure::FailFast` (default) | The block fails as soon as any branch has failed. |
| `ParallelFailure::SettleAll` | Every branch settles first, then the block fails. |

Unlike a single action, the block schedules **every** unrecorded branch before
it suspends — otherwise a run would serialise one branch per drive.

### `fanOut(iterable $items, Closure $using): FanOutBuilder`

One action per item. See [Scale](06-scale.md) — this is capped, deliberately.

```php
$products = $this->fanOut($hits, fn (array $hit) => $this->action(FetchProduct::class, $hit['sku']))
    ->keyBy(fn (array $hit): string => $hit['sku'])
    ->run();
```

### `batch(string $source, mixed ...$arguments): BatchBuilder`

Unbounded work as a single step. See [Scale](06-scale.md).

```php
$summary = $this->batch(ProductSearchSource::class, $query)
    ->using(EnrichProduct::class)
    ->chunk(500)
    ->allowFailures(0.02)
    ->tries(2)
    ->run();
```

### `sideEffect(string $key, Closure $callback): mixed`

Record a value that cannot be recomputed. Runs inline during the drive, once.

```php
$startedAt = $this->sideEffect('started-at', fn () => now()->toIso8601String());
$batchId = $this->sideEffect('batch-id', fn () => (string) Str::ulid());
```

Keep these cheap — anything expensive belongs in an action.

### `signal(string $name): SignalBuilder` / `awaitSignal(...)`

Suspend until something delivers a signal. See
[Signals and timers](05-signals-timers.md).

```php
// Shorthand — a timeout returns null.
$decision = $this->awaitSignal('approval', timeout: now()->addDays(3));

// Builder.
$decision = $this->signal('approval')
    ->timeoutAfter(now()->addDays(3))
    ->default(['approved' => false])
    ->wait();

// Or unwind the run when nobody answers.
$decision = $this->signal('approval')->timeoutAfter(3600)->orFail()->wait();
```

### `sleepUntil(DateTimeInterface $until): void`

Suspend until a wall-clock instant, however far away.

```php
$this->sleepUntil(now()->addWeek());
```

### `tag(string $key, string $value): void`

Attach a queryable tag to the run.

```php
$this->tag('tenant', $tenantId);
$this->tag('supplier', $supplier);
```

```php
Run::query()->where('tags->tenant', 'acme')->get();
```

## Starting a run

```php
use JayI\Impex\Enums\RunTrigger;
use JayI\Impex\Facades\Impex;

$run = Impex::run(
    slug: 'extract-products',
    arguments: ['drill bits', 50],
    trigger: RunTrigger::Api,
    idempotencyKey: $request->header('X-Request-Id'),
    tags: ['tenant' => 'acme'],
    owners: ['customer' => $customer, 'user' => $user],
);
```

Returns immediately with a **pending** run. Nothing executes inline.

```bash
php artisan impex:run extract-products --argument="drill bits" --argument=50
```

```php
// config/impex.php — on a schedule, by slug
'schedule' => ['extract-products' => '0 * * * *'],
```

## Reading a run

```php
$run->status;                 // RunStatus enum
$run->error;                  // ['class' => ..., 'message' => ...] when failed
$run->tags;
Impex::result($run);          // the flow's return value, from column or disk

$run->forwardSteps()->get();  // the recorded history, in replay order
$run->owners;
$run->messages;               // ledger entries caused by this run
```

## Controlling a run

```php
Impex::signal($run, 'approval', ['approved' => true], idempotencyKey: $eventId);
Impex::cancel($run, 'No longer needed');
Impex::retry($run);           // re-queue a drive; completed steps are not re-run
```

## Statuses

| Status | Meaning |
|---|---|
| `pending` | Created, first drive queued. |
| `running` | Work in flight. |
| `waiting` | Blocked on a signal or a timer. Costs nothing while it waits. |
| `rolling back` | A step failed; rollback is walking backwards. |
| `completed` | `handle()` returned. |
| `failed` | A step failed terminally, and any rollback has finished. |
| `cancelled` | Cancelled before finishing. |
