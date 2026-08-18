# Testing flows

## The shipped helpers

```php
use JayI\Impex\Testing\Flows;

it('extracts products', function (): void {
    $run = Flows::run('extract-products', ['drill bits', 5]);

    Flows::assertCompleted($run);
    Flows::assertStepRan($run, SearchProducts::class, times: 1);
    Flows::assertStepDidNotRun($run, RollbackPimWrite::class);
    Flows::assertForwardStepCount($run, 3);
});
```

| Helper | Purpose |
|---|---|
| `Flows::run($slug, $args)` | Start and drive to completion in-process. No worker needed. |
| `Flows::travelTo($moment)` | Move the clock forward and run the sweep — how you test a timeout, a sleep, or a deadline. |
| `Flows::assertCompleted/assertFailed/assertWaiting` | Status, with the run's error in the failure message. |
| `Flows::assertStepRan($run, $action, $times)` | An action ran, optionally exactly N times. |
| `Flows::assertStepDidNotRun` | It did not. |
| `Flows::assertRolledBack($run, $action)` | A rollback completed. |
| `Flows::assertNotRolledBack` | Nothing rolled back. |
| `Flows::assertForwardStepCount` | Pin the cost of a flow — a batch should stay at one step however many items it processes. |
| `Flows::assertAwaitingSignal($run, $name)` | Parked on a named signal. |
| `Flows::redeliverSteps($run)` | Re-run every recorded step's job, as at-least-once delivery would. |

The one worth writing for every flow that touches a non-idempotent upstream:

```php
it('does not repeat a side effect when jobs are redelivered', function (): void {
    $run = Flows::run('checkout', ['order-1']);

    Flows::redeliverSteps($run);
    Flows::redeliverSteps($run);

    Flows::assertStepRan($run, ChargeCard::class, times: 1);
});
```

## The deterministic harness

The `sync` queue driver drives a run to completion inside one call: a drive
schedules a step, the step runs inline, and its completion re-enters the drive
loop.

```php
beforeEach(function (): void {
    config()->set('queue.default', 'sync');
    config()->set('impex.flows', ['extract-products' => ExtractProductsFlow::class]);
});

it('extracts products', function (): void {
    $run = Impex::run('extract-products', ['drill bits', 5]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and(Impex::result($run))->toBe(['products' => 5]);
});
```

Remember to `refresh()` — the model returned by `run()` is the pending one.

## Asserting the history

```php
$steps = $run->forwardSteps()->get();

expect($steps)->toHaveCount(3)
    ->and($steps[0]->name)->toBe(SearchProducts::class)
    ->and($steps[0]->status)->toBe(StepStatus::Completed);
```

## Asserting side effects fired once

The point of the engine is that a step's side effect happens once even under
redelivery. Count them:

```php
it('is idempotent when a step job is redelivered', function (): void {
    $run = Impex::run('extract-products', ['drill bits', 5]);

    // Exactly what SQS at-least-once delivery does.
    app(Engine::class)->executeStep((string) $run->getKey(), 'forward', 0);
    app(Engine::class)->executeStep((string) $run->getKey(), 'forward', 0);

    expect(SearchProducts::$calls)->toBe(1);
});
```

## Testing rollback

```php
it('rolls back the charge when shipping fails', function (): void {
    $run = Impex::run('checkout', ['order-1']);

    expect($run->refresh()->status)->toBe(RunStatus::Failed);

    $rollback = $run->steps()->where('phase', StepPhase::Rollback)->get();

    expect($rollback)->toHaveCount(1)
        ->and($rollback[0]->name)->toBe(RefundCard::class)
        ->and($rollback[0]->undoes_sequence)->toBe(0);
});
```

## Testing a wait

```php
it('waits for approval', function (): void {
    $run = Impex::run('purchase-order', ['po-1']);

    expect($run->refresh()->status)->toBe(RunStatus::Waiting);

    Impex::signal($run, 'approval', ['approved' => true]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed);
});
```

For a timeout, travel and sweep:

```php
Carbon::setTestNow(now()->addDays(4));

$this->artisan('impex:tick')->assertSuccessful();

expect($run->refresh()->status)->toBe(RunStatus::Completed);

Carbon::setTestNow();
```

## Testing a resumable action

Override `shouldYield()` in a test double so the yield point is deterministic
rather than wall-clock dependent:

```php
final class SeedsInPages extends ResumableAction
{
    public function execute(int $pages): mixed
    {
        $page = (int) ($this->cursor() ?? 0) + 1;

        return $page < $pages ? $this->yieldTo((string) $page) : ['pages' => $page];
    }

    protected function shouldYield(): bool
    {
        return true;   // yield on every page
    }
}
```

```php
expect($run->forwardSteps()->count())->toBe(1)      // still one step
    ->and($run->forwardSteps()->first()->resumptions)->toBe(3);
```

## Testing a channel

```php
$body = json_encode(['sku' => 'ABC-1']);

$this->call('POST', '/impex/channels/supplier-feed',
    server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, 'shhh'),
    ],
    content: $body,
)->assertStatus(202);

expect(Message::query()->first()->signature_valid)->toBeTrue();
```

## Testing determinism

Assert a divergence is caught rather than silently corrupting the run:

```php
it('fails a run whose flow changed underneath it', function (): void {
    // …drive partway, mutate the flow's shape, drive again
    expect($run->refresh()->error['class'])->toBe(HistoryMismatchException::class);
});
```

## Config for tests

```php
$app['config']->set('queue.default', 'sync');
$app['config']->set('cache.default', 'array');
$app['config']->set('impex.cache.store', 'array');   // array supports atomic locks
$app['config']->set('database.connections.testing.foreign_key_constraints', true);
```

Keep foreign keys on: several of the engine's guarantees are constraints, and a
test suite that disables them will not catch a broken one.
