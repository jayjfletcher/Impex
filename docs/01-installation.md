# Installation

```bash
composer require jayi/impex
php artisan vendor:publish --tag=impex-config
php artisan vendor:publish --tag=impex-migrations
php artisan migrate
```

## The scheduled sweep is not optional

```php
// routes/console.php, or bootstrap/app.php
Schedule::command('impex:tick')->everyMinute();
```

The package registers this itself when `impex.timers.enabled` is not `false`, so
you only need to add it manually if you have disabled that.

**Without it, a run that sleeps or waits on a signal for longer than the queue's
delay ceiling never wakes.** `impex:tick` is also the repair pass: it reclaims
steps whose invocation was killed mid-flight and finalises batches whose
completion check was throttled.

## Publish tags

| Tag | Contents |
|---|---|
| `impex-config` | `config/impex.php` |
| `impex-migrations` | the seven migrations |
| `impex-assets` | the compiled dashboard bundle |
| `impex-views` | the dashboard shell view, if you want to customise it |
| `impex-lang` | translation strings |
| `impex` | all of the above |

## Vapor checklist

Impex is built for a runtime with a hard execution ceiling, a small queue
message, no local disk and no shared memory. Five settings make that work:

```php
// config/impex.php
'artifacts' => [
    'disk' => 's3',            // Lambda has no persistent local filesystem
],

'cache' => [
    'store' => 'dynamodb',     // locks must be shared across invocations
],

'limits' => [
    'max_step_seconds' => 840, // below Lambda's 900s ceiling
    'lease_seconds' => 900,    // must exceed max_step_seconds
],

'queue' => [
    'connection' => 'sqs',
],
```

`lease_seconds` **must** stay above `max_step_seconds`. If it does not, a
legitimately slow step has its lease reclaimed while it is still working, and
runs twice.

### Why each one

| Constraint | What the package does | What you must set |
|---|---|---|
| 900s execution ceiling | one step per invocation; long steps checkpoint and resume | `limits.max_step_seconds` |
| 256KB SQS message limit | jobs carry ULIDs only; payloads above the threshold go to a disk | `artifacts.disk` |
| 15-minute SQS delay cap | long waits become timer rows swept by `impex:tick` | the schedule entry |
| at-least-once delivery | steps are leased before they execute | `limits.lease_seconds` |
| no shared memory | drives are serialised with a cache lock | `cache.store` |

A cache store that cannot take atomic locks raises an exception naming the store
rather than silently allowing two invocations to replay the same run.

## Verifying the install

```bash
php artisan impex:run --help
php artisan impex:tick
```

A first flow, end to end:

```php
// app/Flows/PingFlow.php
final class PingFlow extends \JayI\Impex\Flows\Flow
{
    public function handle(string $message): array
    {
        return $this->action(\App\Flows\Actions\Echo::class, $message)->run();
    }
}

// app/Flows/Actions/Echo.php
final class Echo
{
    public function execute(string $message): array
    {
        return ['echoed' => $message];
    }
}

// config/impex.php
'flows' => ['ping' => \App\Flows\PingFlow::class],
```

```bash
php artisan impex:run ping --argument="hello"
php artisan queue:work --once
```
