# Running on Lambda and Vapor

Impex is built for a runtime with a hard execution ceiling, a small message
size, a short delay cap, no persistent local disk, and no shared memory. Each
of those shapes something in the package.

## The execution ceiling

Lambda kills an invocation at 900 seconds with no warning, no shutdown hook, and
nothing to catch. Reacting to a timeout is impossible; the only option is to
stop before it.

`impex.limits.max_step_seconds` (default 840) is the working window, and
`resume_margin_seconds` (default 30) is the headroom, so `shouldYield()` flips
at ~810 seconds — enough time to checkpoint and return cleanly.

```php
final class SeedProducts extends ResumableAction
{
    public function execute(string $query): mixed
    {
        $cursor = $this->cursor();          // null on the first invocation

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

The engine stores the cursor on the step, releases the lease, and re-dispatches
**the same step** — same sequence, so the replay is unaffected. A step that
resumed forty times is indistinguishable from a slow one to the flow that
scheduled it.

Two guards, because a resume loop is worse than a timeout:

- yielding the **same cursor twice** raises `StalledStepException`
- exceeding `impex.limits.max_resumptions` fails the step

If the invocation is killed anyway, the lease lapses, `impex:tick` reclaims the
step, and it re-runs **from its last stored cursor** — losing one window of
work, not the whole job. This is why `lease_seconds` (900) must sit above
`max_step_seconds` (840): the lease must not expire while the step is
legitimately still working.

## The 256KB message limit

SQS rejects messages above 256KB. Impex jobs therefore carry **ULIDs only** —
`DriveRun` holds a run id, `ExecuteStep` holds a run id, a phase, and a
sequence. Arguments and results are read from the database or the artifact disk
inside the engine.

Anything serializing above `impex.artifacts.inline_threshold` (default 64KB) is
written to `impex.artifacts.disk` and referenced by artifact id. Point that disk
at S3 on Vapor; Lambda has no persistent local filesystem.

## The 15-minute delay cap

SQS caps message delay at 15 minutes, so a run that waits a day for an approval
cannot be a delayed job. Long waits become rows in `impex_timers`, swept once a
minute by `impex:tick`:

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('impex:tick')->everyMinute();
}
```

The package registers this for you when `impex.timers.enabled` is not false.
**If it is not running, a sleeping run never wakes.**

The sweep claims rows under a lease rather than a bare `claimed_at IS NULL`
predicate, so a timer whose claimer died between claiming and dispatching
becomes claimable again instead of stranding forever.

## At-least-once delivery

SQS delivers a message at least once. A step is therefore **claimed before it
executes**: `ExecuteStep` takes a lease, and a redelivered job that loses the
claim exits without side effects. The result is written scoped to the lease
token, so a zombie that wakes after its lease lapsed cannot clobber the winner.

At the trigger boundary, pass an idempotency key:

```php
Impex::run('extract-products', [$query], idempotencyKey: $request->header('X-Request-Id'));
```

A repeat returns the original run rather than starting a second one.

## No shared memory

Two invocations of the same run must not replay concurrently. Impex serialises
drives with `Cache::lock()` on `impex.cache.store`, which must be a **shared,
lock-capable store** — Redis or DynamoDB on Vapor. An invocation that cannot
take the lock marks the run dirty and returns rather than blocking, and the
holder re-reads the history before releasing.

## The API Gateway timeout

Trigger endpoints return `202` with a run id and never block on the work.
`impex.limits.sync_seconds` caps how long a caller may wait when it explicitly
asks to, well inside API Gateway's ~30-second limit.

## Scale: fanOut vs batch

Replay is O(history) per drive, and a drive happens per completed step, so N
per-item steps cost roughly N²/2 step-row reads across the run:

| items | step rows read |
|---|---|
| 100 | ~5,000 |
| 1,000 | ~500,000 |
| 10,000 | ~50,000,000 |

`fanOut()` is therefore capped at `impex.limits.fan_out_max` (default 100) and
raises `FanOutTooLargeException` above it. Use `batch()` for anything larger:
one step in the replay history whatever the item count, with per-item state in
`impex_batch_items`, which the replay never reads.

The tradeoff: batched items get no per-item rollback, no positional results,
and no per-item signals. Use `fanOut` when one item failing should unwind the
run item-by-item; use `batch` for throughput.

## Concurrency

A million-item batch can saturate an account's Lambda concurrency and starve
the web tier. Route the run to its own queue (`queue_connection` and `queue` on
the run, which its jobs inherit) and cap that queue's concurrency before running
a sweep against production.
