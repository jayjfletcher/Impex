# Extending

## Registering flows from another package

`Impex::flows()->register()` is safe to call from any service provider's
`boot()`, in any order.

```php
namespace VendorName\Catalogue;

use Illuminate\Support\ServiceProvider;
use JayI\Impex\Flows\FlowRegistry;

final class CatalogueServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // callAfterResolving means this works whether Impex booted before or
        // after this provider — the closure runs when the registry is built.
        $this->callAfterResolving(FlowRegistry::class, function (FlowRegistry $flows): void {
            $flows->registerMany([
                'catalogue:sync' => SyncCatalogueFlow::class,
                'catalogue:reindex' => ReindexFlow::class,
            ]);
        });
    }
}
```

### Precedence

**The application's config always wins over a package's registration**, and this
does not depend on boot order. An application can point a slug a package ships
at its own subclass:

```php
// config/impex.php
'flows' => [
    'catalogue:sync' => \App\Flows\OurSyncFlow::class,   // wins
],
```

The package's own view stays readable, which is what makes the override visible
rather than mysterious:

```php
Impex::flows()->registered()['catalogue:sync'];   // the package's class
Impex::flows()->class('catalogue:sync');          // the app's class
```

### Collisions

Two packages claiming the same slug is an **error**, not a silent shadowing:

```
JayI\Impex\Exceptions\FlowCollisionException

  The flow slug [sync] is already registered to [VendorA\SyncFlow], and
  [VendorB\SyncFlow] tried to claim it. Prefix the slug with the package name,
  or set `impex.flows.sync` in the application config to choose explicitly —
  config always wins over a package registration.
```

Prefix your slugs (`catalogue:sync`) and this never comes up. Registering the
same class under the same slug twice is a no-op, so a provider that boots more
than once does no harm.

## Swapping engine internals

The engine is a facade over collaborators, each resolved from the container. To
change one, bind your own — no forking, no subclassing the engine.

| Bind | To change |
|---|---|
| `RollbackStrategy` | What a failed run unwinds, and in what order |
| `StepWriter` | How steps are recorded — extra columns, a different payload policy |
| `JobRouter` | How engine jobs are routed onto queues |
| `EngineOptions` | Where the tuning comes from, if not config |
| `Waits` | Signal delivery, sleeps, timer firing, deadline enforcement |
| `Children` | How child runs are started and reported back |
| `Sweeper` | What the periodic pass does |

```php
// A strategy that unwinds only as far as a marked step.
final class UnwindToCheckpoint implements RollbackStrategy
{
    public function __construct(private readonly Rollbacks $default) {}

    public function next(Run $run): bool
    {
        if ($run->tags['checkpointed'] ?? false) {
            return false;   // nothing to undo past the checkpoint
        }

        return $this->default->next($run);
    }

    public function halts(RunStep $rollbackStep): bool
    {
        return $this->default->halts($rollbackStep);
    }
}
```

```php
// A service provider
$this->app->bind(RollbackStrategy::class, UnwindToCheckpoint::class);
```

Decorating the shipped implementation, as above, is usually better than
replacing it — the default carries the parts that are easy to get wrong, like
settling a failed rollback so the unwind cannot loop.

### The sweep

`Sweeper` is a class rather than logic inside `impex:tick`, so you can call it
from a job or a health check:

```php
$report = app(Sweeper::class)->sweep();

$report->idle();          // nothing to do
$report->timers;          // fired
$report->leases;          // reclaimed
$report->expiredSteps;    // past their deadline
$report->summary();       // one line, for a log or a command
```

### Tuning

`EngineOptions` reads the config once and validates it. `lease_seconds` at or
below `max_step_seconds` throws with both values named, rather than surfacing
later as a slow step that ran twice.

## Contracts

Four, each with a real second implementation or a real host-app need. Everything
else is a concrete class, and becomes a contract when a second implementation
actually exists.

| Contract | Purpose |
|---|---|
| `SignatureValidator` | Verifying an inbound request — every upstream signs differently |
| `ChannelProfile` | Deciding which inbound requests become runs |
| `BatchSource` | Streaming resumable pages of work into a batch |
| `RollbackStrategy` | What a failed run unwinds, and in what order |
| `Resumable` | An action that checkpoints and resumes (via `ResumableAction`) |

Each is resolved at the point of use from a config value, so swapping one is a
config change:

```php
'channels' => [
    'stripe' => ['signature_validator' => \App\Impex\StripeSignatureValidator::class],
],
```

## Events

Every state transition emits one.

| Event | Payload |
|---|---|
| `RunStarted` | `$runId` |
| `RunCompleted` | `$runId` |
| `RunFailed` | `$runId` |
| `StepCompleted` | `$runId`, `$stepId` |
| `StepFailed` | `$runId`, `$stepId` |
| `MessageRecorded` | `$messageId` |

```php
use Illuminate\Support\Facades\Event;
use JayI\Impex\Events\RunFailed;
use JayI\Impex\Models\Run;

Event::listen(function (RunFailed $event): void {
    $run = Run::query()->find($event->runId);

    Log::error('Impex run failed', [
        'run' => $event->runId,
        'flow' => $run?->flow,
        'error' => $run?->error,
    ]);
});
```

Events carry identifiers, not models, so a listener queued onto SQS stays well
inside the message limit.

## Registering channels from a package

Channels come from config, so a package merges its own:

```php
public function register(): void
{
    $this->mergeConfigFrom(__DIR__.'/../config/catalogue-channels.php', 'impex.channels');
}
```

Note `mergeConfigFrom` is shallow — an application redefining the same channel
key replaces it wholesale, which is usually what you want.

## Custom artifact storage

Point the disk at any Flysystem adapter:

```php
'artifacts' => ['disk' => 'supplier-archive', 'path' => 'impex', 'inline_threshold' => 65536],
```

## Swapping the queue per flow

```php
$run = Impex::run('extract-products', [$query]);

$run->update(['queue_connection' => 'sqs-slow', 'queue' => 'impex-bulk']);
```

Every job the run dispatches after that inherits the routing.
