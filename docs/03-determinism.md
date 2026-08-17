# Determinism — the replay contract

## What replay is

`handle()` is not run once. It runs again from the top every time the run is
driven forward — after each step completes, after a resume, after a worker
restart. What makes that safe is that every DSL call is keyed by its **position
in the replay**, and a step already recorded returns its stored result instead
of executing again.

```
drive 1:  handle() → action(A) unrecorded  → record seq 0, dispatch, suspend
drive 2:  handle() → action(A) at seq 0    → returns recorded result
                   → action(B) unrecorded  → record seq 1, dispatch, suspend
drive 3:  handle() → action(A) seq 0       → recorded
                   → action(B) seq 1       → recorded
                   → returns; run completes
```

`handle()` ran three times. Each action ran once.

## The rule

Given the same recorded history, `handle()` must make **the same DSL calls in
the same order**. Everything else follows.

## What breaks it

Anything read from outside the recorded history.

```php
// WRONG — a different value on a later drive
if (now()->hour < 12) {
    $this->action(MorningImport::class)->run();
}

// WRONG — the row may change between drives
$config = Setting::where('key', 'batch_size')->first();
$this->action(Import::class, $config->value)->run();

// WRONG — a new id every drive
$this->action(Import::class, (string) Str::ulid())->run();

// WRONG — order not guaranteed, so sequences shift
foreach (Product::all() as $product) {
    $this->action(Sync::class, $product->id)->run();
}
```

Each can produce a different sequence of calls on drive 2 than on drive 1. The
engine detects the divergence and fails the run with
`HistoryMismatchException` rather than corrupting it — but the run still fails.

## The fix

Wrap the read so its value is recorded once and reused:

```php
$hour = $this->sideEffect('hour', fn (): int => now()->hour);

if ($hour < 12) {
    $this->action(MorningImport::class)->run();
}
```

```php
$size = $this->sideEffect('batch-size', fn (): int => Setting::batchSize());

$this->action(Import::class, $size)->run();
```

For a collection, make it a step result:

```php
$products = $this->action(ListProducts::class)->run();   // recorded

foreach ($products as $product) {
    $this->action(Sync::class, $product['id'])->run();
}
```

## Branching is fine

Determinism does not mean the flow cannot branch. It means the branch must be
decided by something recorded:

```php
$result = $this->action(Validate::class, $payload)->run();

if ($result['valid']) {
    $this->action(Import::class, $payload)->run();
} else {
    $this->action(Quarantine::class, $payload)->run();
}
```

`$result` came from a recorded step, so the branch is taken identically on every
drive. This is the normal shape of a flow.

## Loops are fine, with the same caveat

```php
$pages = $this->action(CountPages::class, $query)->run();

for ($page = 1; $page <= $pages['total']; $page++) {
    $this->action(FetchPage::class, $query, $page)->run();
}
```

`$pages['total']` is recorded, so the loop runs the same number of times every
drive. A loop bounded by an unrecorded value is a divergence.

## Reading the exception

```
JayI\Impex\Exceptions\HistoryMismatchException

  Replay diverged at sequence 3: history recorded [action:App\Actions\FetchPricing]
  but the flow asked for [action:App\Actions\FetchInventory]. Wrap
  non-deterministic reads in sideEffect(), and do not change a flow while its
  runs are live.
```

Sequence 3 is the fourth DSL call. Count them in `handle()` — including
`sideEffect`, `awaitSignal`, `sleepUntil`, and each `parallel()` branch — to
find the point of divergence.

A run that hits this is **not compensated**. The recorded history no longer
describes what the code does, so a rollback would be guesswork; the run fails
and is left for you to inspect.

## Deploying under a live run

Changing `handle()` while runs of that flow are in flight is the one divergence
the engine cannot help with: the recorded history describes code that no longer
exists. `flow_class` and `flow_version` are recorded on the run so the engine
can *detect* this — they cannot pin the old code.

Before changing a flow's shape, drain its active runs:

```php
Run::query()->where('flow', 'extract-products')->active()->count();
```

**Adding steps to the end of `handle()` is safe.** Inserting, removing, or
reordering steps before existing ones is not.

## What the engine does not guarantee

The engine guarantees a step is not *recorded* twice. It cannot guarantee an
upstream side effect happened only once — a lease can lapse mid-flight and be
reclaimed by design, which is what stops a killed invocation wedging a run
forever.

So the guarantee is **at-most-once per lease**, not exactly-once. Any action
that charges a card, sends an email, or writes to a non-idempotent API needs its
own idempotency key:

```php
final class ChargeCard
{
    public function execute(string $orderId, int $amount): array
    {
        return $this->stripe->charge($amount, [
            // Derived from the run, so a reclaimed lease charges once.
            'idempotency_key' => "order-{$orderId}",
        ]);
    }
}
```
