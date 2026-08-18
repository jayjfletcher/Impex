# Versioning

Replay means `handle()` is re-executed from the top on every drive. Change it
while runs are live and the recorded history no longer describes the code —
which the engine detects and fails on, but detection is not a fix.

Versioning is the fix.

## Declaring a version

```php
final class ExtractProductsFlow extends Flow
{
    public const VERSION = 'v2';

    public function handle(string $query): array
    {
        $hits = $this->action(SearchProducts::class, $query)->run();

        // Runs that started before v2 keep taking the path they began with.
        if ($this->version() === 'v1') {
            return ['products' => $this->action(WriteToPimLegacy::class, $hits)->run()];
        }

        [$pricing, $inventory] = $this->parallel()
            ->action(FetchPricing::class, $hits)
            ->action(FetchInventory::class, $hits)
            ->run();

        return ['products' => $this->action(WriteToPim::class, $hits, $pricing, $inventory)->run()];
    }
}
```

Every run records the version it started under. `$this->version()` returns it,
so the same class can serve both the runs already in flight and the new ones.

## Deploying a change safely

1. Keep the existing code path intact, guarded by the old version.
2. Add the new path.
3. Bump `VERSION`.

New runs record `v2` and take the new path. Runs already in flight recorded `v1`
and keep taking the old one. Once the `v1` runs have drained, delete the branch:

```php
Impex::query()->whereVersion('v1')->active()->count();   // 0 = safe to remove
```

## Setting a version explicitly

```php
Impex::run('extract-products', [$query], version: 'v1');
```

```
POST impex/flows/extract-products/runs
{ "arguments": ["drill bits"], "version": "v1" }
```

Useful for backfills that must run through the old path deliberately.

## What versioning does not cover

Versioning branches *within* one class. It does not help if you repoint a slug
at a **different class** — the recorded history describes the original, so the
engine refuses:

```
JayI\Impex\Exceptions\FlowVersionMismatchException

  The run started on [App\Flows\OldFlow] but the slug [extract-products] now
  resolves to [App\Flows\NewFlow]. The recorded history describes the original
  class, so it cannot be replayed against this one. Drain runs of a flow before
  repointing its slug.
```

Checked on every drive, so a repointed slug fails loudly on the next step rather
than replaying into the wrong code.

## Deadlines

Related, because both are about runs that outlive your attention.

```php
// A whole run
Impex::run('extract-products', [$query], expiresAt: 3600);

// One step
$this->action(FetchPricing::class, $skus)->expiresAt(now()->addMinutes(10))->run();
```

```php
// config/impex.php — defaults, in seconds. Null means no deadline.
'deadlines' => ['run' => null, 'step' => null],
```

Enforcement happens in `impex:tick`, **not in-process**: a step that has handed
control to an upstream call cannot check a clock, and a killed invocation never
gets the chance. A step that passes its deadline fails and unwinds the run like
any other failure; a run that passes its own compensates.

This is the other half of the lease discipline. A lease stops a *killed*
invocation wedging a run; a deadline stops a *hung* one running forever.
