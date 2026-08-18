# Compensation

When a step fails terminally, the run enters `compensating` and walks the
recorded steps **in reverse**, running each registered rollback.

```php
final class CheckoutFlow extends Flow
{
    public function handle(string $orderId): array
    {
        $charge = $this->action(ChargeCard::class, $orderId)
            ->compensateWith(RefundCard::class, $orderId)
            ->run();

        $this->action(ReserveStock::class, $orderId)
            ->compensateWith(ReleaseStock::class, $orderId)
            ->run();

        $this->action(ShipOrder::class, $orderId)->run();   // no rollback: shipping is final

        return ['charge' => $charge];
    }
}
```

If `ShipOrder` fails: `ReleaseStock` runs, then `RefundCard`. If `ReserveStock`
fails: only `RefundCard` runs.

## Why a class, not a closure

```php
// Not supported.
->compensateWith(fn () => Reservation::release($id))
```

The rollback is captured **when the forward step is recorded**, so compensation
never has to replay the flow to discover what to undo — which matters, because
the flow may have already diverged by the time you are rolling back. A closure
cannot be stored in a database column; a class name and its arguments can.

## Compensation is a phase, not a step

Compensation steps live in their own sequence space, `phase = compensation`, so
they never collide with the forward history the replay reads.

```php
$run->steps()->where('phase', StepPhase::Compensation)->get();
```

```
GET impex/runs/{run}/steps?phase=compensation
```

In the dashboard the rollback path renders on a dashed rail beneath the forward
timeline.

## When a compensation itself fails

Set per saga group:

```php
$this->saga()
    ->onCompensationFailure(CompensationFailure::Continue)
    ->step(ChargeCard::class, $id)->compensateWith(RefundCard::class, $id)
    ->run();
```

| Policy | Behaviour |
|---|---|
| `CompensationFailure::Stop` (default) | Rollback halts. The run fails with a `rollback` note in its error saying which compensation failed, and is left partly compensated for inspection. |
| `CompensationFailure::Continue` | The failed rollback is marked skipped, its target is recorded as compensated, and the rollback moves on. |

Marking the failed one skipped matters: without it the same target is selected
again on the next drive, because it is still uncompensated — an endless loop.

Stopping is the default because a half-completed rollback that keeps going can
compound the damage — releasing stock for an order whose refund failed leaves
you worse off than stopping and paging someone.

## What compensation cannot undo

- **A `HistoryMismatchException`.** The recorded history no longer describes the
  code, so the engine fails the run without compensating rather than guessing.
- **Batched items.** `batch()` rolls back as a unit, not item by item. Use
  `fanOut()` when a single item failing should unwind the run individually.
- **Anything the compensation action does not implement.** A rollback is
  ordinary code; the engine only guarantees it is called, with the arguments
  captured at the time.

## Idempotent rollbacks

A compensation step is leased and reclaimable like any other, so it can run more
than once if an invocation is killed after doing its work but before recording
it. Write rollbacks that tolerate that:

```php
final class ReleaseStock
{
    public function execute(string $orderId): array
    {
        $reservation = Reservation::where('order_id', $orderId)->first();

        // Already released: nothing to do, and no error.
        if ($reservation === null || $reservation->released_at !== null) {
            return ['released' => false];
        }

        $reservation->release();

        return ['released' => true];
    }
}
```
