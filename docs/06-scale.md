# Scale — fanOut, batch, and resuming

Three different limits, three different tools.

| Problem | Tool |
|---|---|
| A handful of items, each needing its own retry and rollback | `fanOut()` |
| More items than a replay history can hold | `batch()` |
| One action that cannot finish inside a single invocation | `ResumableAction` |

## Why per-item steps do not scale

Replay is O(history) per drive, and a drive happens each time a step completes.
N per-item steps therefore cost roughly N²/2 step-row reads across the run:

| N items | step rows read across the run |
|---|---|
| 100 | ~5,000 |
| 1,000 | ~500,000 |
| 10,000 | ~50,000,000 |
| 1,000,000 | ~500,000,000,000 |

At a million you also get a million rows in `impex_run_steps` for one run, a
million queue messages, and a `DriveRun` invocation that has to dispatch them.
None of that fits in fifteen minutes.

## `fanOut()` — bounded, per-item

```php
$products = $this->fanOut($hits, fn (array $hit) => $this->action(FetchProduct::class, $hit['sku']))
    ->keyBy(fn (array $hit): string => $hit['sku'])
    ->failurePolicy(ParallelFailure::WaitAllThenFail)
    ->run();
```

Each item becomes its own recorded step, so items retry and compensate
individually and results come back in key order.

Above `impex.limits.fan_out_max` (default 100) it refuses:

```
JayI\Impex\Exceptions\FanOutTooLargeException

  fanOut() received 5000 items, above the configured cap of 100
  (impex.limits.fan_out_max). Replay is O(history) per drive, so 5000 per-item
  steps would cost roughly 12,500,000 step-row reads across the run. Use batch()
  for collections of this size, or raise the cap if the flow is short.
```

### The fingerprint

Sequences are positional, so a fan-out is only safe while the collection it
walks is stable. Usually it is — the collection is the recorded result of a
previous step, replayed byte-identically.

Where it is not, a marker step records a fingerprint of the collection plus the
key order. A replay whose collection no longer matches fails immediately,
naming the fan-out, instead of silently handing item B's result to item A's
continuation:

```
The fan-out at sequence 1 walked a different collection than the one recorded
(40 item(s) then, 41 now). Fan out over the result of a recorded step rather
than a fresh read, or call keyBy() so items are resolved by key instead of by
position.
```

### `keyBy()`

Optional. With it, items are resolved against the recorded **keys** rather than
their position, so a re-ordered collection still maps to the same steps. Without
it, position is the identity and the whole collection is hashed.

Use it when the collection's order is not guaranteed between drives. Items may
be re-ordered; they may not be removed.

## `batch()` — unbounded

The insight: per-item work does not need *replay* semantics. It needs idempotent
completion tracking, which is far cheaper. So the whole batch is **one** step in
the replay history, and per-item state lives in `impex_batch_items` — a table
the replay never reads.

### The source

Streams work in resumable pages. Returns a cursor rather than a generator,
because PHP generators cannot be serialized across invocations and seeding a
large source will span more of them than any one may live for.

```php
namespace App\Flows\Sources;

use App\Models\Product;
use JayI\Impex\Contracts\BatchSource;
use JayI\Impex\Runtime\BatchChunk;
use JayI\Impex\Runtime\BatchChunkItem;

final class ProductSearchSource implements BatchSource
{
    public function __construct(private readonly string $query) {}

    public function chunk(?string $cursor, int $size): BatchChunk
    {
        $page = Product::query()
            ->where('name', 'like', "%{$this->query}%")
            ->when($cursor, fn ($q) => $q->where('sku', '>', $cursor))
            ->orderBy('sku')
            ->limit($size)
            ->get();

        if ($page->isEmpty()) {
            return BatchChunk::last();
        }

        return BatchChunk::of(
            $page->map(fn (Product $p) => new BatchChunkItem(
                key: $p->sku,                      // the item's idempotency key
                payload: ['sku' => $p->sku],
            ))->all(),
            $page->last()->sku,                    // resume after this
        );
    }
}
```

Keyset pagination maps onto this directly — as does Elasticsearch's
`search_after`, whose sort values are exactly this cursor.

`key` is what makes an item idempotent: `unique(batch_id, item_key)` means a
redelivered seed loses the insert race and exits, exactly as
`unique(run_id, sequence)` does for steps. Derive it from something stable in
the source data, never from a position.

### The worker

An ordinary action. It has no idea it is in a batch.

```php
final class EnrichProduct
{
    /**
     * @param  array{sku: string}  $item
     * @return array<string, mixed>
     */
    public function execute(array $item): array
    {
        return [
            'sku' => $item['sku'],
            'price' => $this->pricing->for($item['sku']),
            'stock' => $this->inventory->for($item['sku']),
        ];
    }
}
```

### The flow

```php
final class SweepCatalogueFlow extends Flow
{
    public function handle(string $query): array
    {
        $summary = $this->batch(ProductSearchSource::class, $query)
            ->using(EnrichProduct::class)
            ->chunk(500)
            ->allowFailures(0.02)   // tolerate 2% dead SKUs
            ->tries(2)              // per item
            ->run();

        // [
        //   'batch_id'  => '01JQ…',
        //   'total'     => 1000000,
        //   'succeeded' => 999812,
        //   'failed'    => 188,
        // ]

        $this->action(WriteToPim::class, $summary['batch_id'])
            ->compensateWith(RollbackPimWrite::class, $summary['batch_id'])
            ->run();

        return $summary;
    }
}
```

### Reading results

Results are not returned positionally — a million values would not fit in
memory. Stream them:

```php
foreach (Impex::batchItems($summary['batch_id']) as $item) {
    $item->item_key;
    $item->status;                        // completed / failed
    $item->result['value'] ?? null;
    $item->error['message'] ?? null;
}
```

```php
// Just the failures
BatchItem::query()
    ->where('batch_id', $batchId)
    ->where('status', StepStatus::Failed)
    ->get();
```

### At a million items

| | `fanOut` | `batch` |
|---|---|---|
| rows in `impex_run_steps` | 1,000,004 | **2** |
| step rows read across the run | ~5×10¹¹ | **~4** |
| `DriveRun` invocations | 1,000,000 | **3** |
| peak memory holding results | all of them | one chunk |

### What batch costs you

| | `fanOut` | `batch` |
|---|---|---|
| per-item retry | yes | yes |
| **per-item compensation** | yes | **no** — rollback is per batch |
| **per-item result, in position** | yes | **no** — aggregate + streamed items |
| per-item signals / sleeps | yes | no |
| item ceiling | ~100 (configurable) | none |
| partial-failure tolerance | policy per block | `allowFailures()` threshold |

If a single item failing should unwind the whole run item by item, use
`fanOut`. If items are independent and you care about throughput and a summary,
use `batch`.

## `ResumableAction` — one long action

A Lambda timeout **cannot be caught**. The invocation is killed with no warning,
no shutdown hook, nothing to `finally` your way out of. So a long action has to
decide to stop *before* the ceiling:

```php
namespace App\Flows\Actions;

use JayI\Impex\Flows\ResumableAction;

final class ImportCatalogueFile extends ResumableAction
{
    public function execute(string $path): mixed
    {
        $offset = (int) ($this->cursor() ?? 0);
        $handle = Storage::readStream($path);
        fseek($handle, $offset);

        while (($line = fgets($handle)) !== false) {
            $this->importRow($line);
            $offset = ftell($handle);

            if ($this->shouldYield()) {
                fclose($handle);

                return $this->yieldTo((string) $offset);
            }
        }

        fclose($handle);

        return ['imported' => true, 'bytes' => $offset];
    }
}
```

| Method | Meaning |
|---|---|
| `cursor()` | The checkpoint the previous invocation yielded, `null` on the first. |
| `shouldYield()` | Whether this invocation is close enough to its ceiling to stop. |
| `secondsRemaining()` | Seconds left before it must stop. |
| `yieldTo($cursor, ?$delaySeconds)` | Checkpoint and ask to be re-dispatched. |

The engine stores the cursor, releases the lease, and re-dispatches the **same
step** — same sequence, so the replay is unaffected. A step that resumed forty
times is indistinguishable from a slow one to the flow that scheduled it.

### Tuning the margin

```php
'limits' => [
    'max_step_seconds' => 840,        // the working window, below Lambda's 900
    'resume_margin_seconds' => 30,    // shouldYield() flips at 810
    'lease_seconds' => 900,           // must exceed max_step_seconds
    'max_resumptions' => 10000,
],
```

`shouldYield()` goes true at 810s, leaving 30s to write the checkpoint and
return cleanly. Widen the margin if a single checkpoint is expensive.

### The guards

A resume loop is worse than a timeout, so two things stop one:

- **The same cursor twice** raises `StalledStepException`. An action that yields
  without advancing would otherwise burn your Lambda concurrency forever.
- **`max_resumptions` exceeded** fails with a message telling you to widen the
  work per invocation or raise the limit.

### If the invocation is killed anyway

The lease lapses, `impex:tick` reclaims the step, and it re-runs from its **last
stored cursor** — losing one window of work, not the whole job. This is why
`lease_seconds` must sit above `max_step_seconds`: the lease must not expire
while the step is legitimately still working.

Batch seeding uses this same protocol internally, so seeding a million-row
source is bounded the same way.
