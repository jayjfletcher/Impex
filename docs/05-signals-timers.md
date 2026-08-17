# Signals and timers

A run that is waiting costs nothing. It holds no worker, no connection, and no
queue message — it is a row with `status = waiting` and a timer row telling
`impex:tick` when to look at it again.

## Two ways to wait

```php
// Shorthand. A timeout returns null.
$decision = $this->awaitSignal('approval', timeout: now()->addDays(3));

// Builder, when you want more than a deadline.
$decision = $this->signal('approval')
    ->timeoutAfter(now()->addDays(3))     // or ->timeoutAfter(3600) in seconds
    ->default(['approved' => false])      // what a timed out wait returns
    ->wait();

// Or make a missing signal a failure worth compensating for.
$decision = $this->signal('approval')
    ->timeoutAfter(now()->addDays(3))
    ->orFail()                            // throws SignalTimeoutException
    ->wait();
```

| Method | Effect |
|---|---|
| `timeoutAfter(DateTimeInterface\|int)` | Give up at this instant, or after this many seconds. |
| `default(mixed)` | What a timed out wait returns. Defaults to `null`. |
| `orFail()` | Throw `SignalTimeoutException` instead, unwinding the run. |
| `wait()` | Suspend until the signal arrives or the deadline passes. |

A timed out wait returning a value rather than throwing is the default because
"nobody approved this in three days" is usually a branch, not a crash. `orFail()`
is there for when it genuinely is one.

### A timeout is not a null payload

The engine records a timed out wait as a **skipped** step, not as completed with
`null`. So a signal that genuinely carries `null` is distinguishable from one
that never came:

```php
$decision = $this->signal('approval')->timeoutAfter(now()->addDay())
    ->default('__timeout__')
    ->wait();

$decision === '__timeout__';   // the deadline passed
$decision === null;            // someone signalled with a null payload
```

## Waiting for a human

```php
final class PurchaseOrderFlow extends Flow
{
    public function handle(string $orderId): array
    {
        $this->action(DraftOrder::class, $orderId)->run();

        $decision = $this->awaitSignal('approval', timeout: now()->addDays(3));

        if ($decision === null) {
            // The timeout fired. A signal that never arrives completes the
            // wait with null rather than failing the run, so the flow decides.
            $this->action(EscalateOrder::class, $orderId)->run();

            return ['approved' => false, 'escalated' => true];
        }

        if (! ($decision['approved'] ?? false)) {
            return ['approved' => false];
        }

        $this->action(SubmitOrder::class, $orderId)->run();

        return ['approved' => true];
    }
}
```

## Delivering a signal

```php
Impex::signal($run, 'approval', ['approved' => true, 'by' => $user->id], idempotencyKey: $eventId);
```

A signal is accepted by **any unfinished run** — `pending`, `running`, or
`waiting`. Delivering to a run that has already finished throws
`CannotSignalTerminalRunException`, because accepting it would leave a row
nothing will ever consume while the caller believed the run had been told
something.

When losing that race is expected rather than exceptional:

```php
$delivered = Impex::signalIfRunning($run, 'approval', ['approved' => true]);
// false if the run had already finished
```

From the console:

```bash
php artisan impex:signal 01JQ7X… approval --payload='{"approved":true}'
php artisan impex:signal 01JQ7X… approval --if-running
php artisan impex:signal 01JQ7X… approval --idempotency-key=evt_9f2
```

### Finding the run to signal

```php
Run::query()
    ->signalable()                       // NOT running()
    ->where('flow', 'purchase-order')
    ->where('tags->order', $orderId)
    ->first();
```

`signalable()` is an alias of `active()`, and it is the scope to reach for:
filtering by `running()` would miss exactly the runs that are parked waiting for
a signal.

```
POST impex/runs/{run}/signals
{ "name": "approval", "payload": { "approved": true }, "idempotency_key": "evt_9f2" }
```

`202` on delivery. **`409` if the run has finished** — a conflict, not a
validation error: the request was well-formed, the run's state made it
impossible. Pass `"if_running": true` to get `200` with `data: null` instead.

Over MCP, `signal-run` takes the same arguments and reports
`{"delivered": false, "reason": "The run has finished."}` when `if_running`
turned a conflict into a no-op.

## Signals delivered early are held

A signal sent before the run reaches its `awaitSignal` is stored, not lost. When
the flow arrives at the wait it consumes the held signal and carries straight
on. This removes the race you would otherwise have between the run's progress
and an external system's speed.

`unique(run_id, name, idempotency_key)` means a redelivered webhook cannot
deliver the same signal twice.

## Sleeping

```php
$this->sleepUntil(now()->addWeek());
$this->sleepUntil($invoice->due_at);
```

## Why timers exist

**SQS caps message delay at 15 minutes.** A workflow waiting a day for approval
cannot be expressed as `->delay()`. Impex writes a row to `impex_timers` and a
once-a-minute scheduled sweep fires it:

```sql
UPDATE impex_timers SET claimed_at = now(), claim_token = ?
WHERE wake_at <= now() AND fired_at IS NULL
  AND (claimed_at IS NULL OR claimed_at < now() - INTERVAL '5 minutes')
```

The lease clause matters: a bare `claimed_at IS NULL` predicate would strand a
timer forever if the sweep died between claiming and dispatching.

```php
// config/impex.php
'timers' => [
    'max_queue_delay' => 900,   // below this, a native queue delay is fine
    'claim_seconds' => 300,     // how long a claim is held before reclaim
    'batch' => 250,             // timers fired per sweep
],
```

**If `impex:tick` is not scheduled, a sleeping run never wakes.**

## Inspecting waits

```php
Run::query()->where('status', RunStatus::Waiting)->get();

Timer::query()->whereNull('fired_at')->orderBy('wake_at')->get();

// Which signal is this run waiting for?
$run->forwardSteps()->where('type', StepType::Signal)->where('status', StepStatus::Pending)->first();
```
