# Child workflows

A child is a **run in its own right** — its own history, its own compensation,
its own row in the dashboard — linked back to its parent by `parent_run_id`.

```php
final class OnboardCustomerFlow extends Flow
{
    public function handle(string $customerId): array
    {
        $account = $this->action(CreateAccount::class, $customerId)->run();

        // Each is a separate run, with its own steps and its own rollback.
        $billing = $this->child('provision-billing', $customerId)->run();
        $catalogue = $this->child('seed-catalogue', $customerId)->run();

        return ['account' => $account, 'billing' => $billing, 'catalogue' => $catalogue];
    }
}
```

The parent parks on the child exactly as it would on any other step, so a child
that takes a week costs the parent nothing while it waits.

## Why not just call the actions inline?

Use `child()` when the work is a **different workflow** — one you would also run
on its own, that has its own compensation and its own operational meaning. Use
`action()` when it is a step of this one. Use `batch()` or `fanOut()` when it is
more of the same work.

The practical difference: a child appears in the runs list on its own, can be
retried and cancelled independently, and rolls back with its own steps.

## Close policies

What happens to a child still running when its parent finishes:

| Policy | Behaviour |
|---|---|
| `ChildClosePolicy::Fail` (default) | The parent waits for the child, and a failed child fails the parent. |
| `ChildClosePolicy::Cancel` | Running children are cancelled when the parent finishes. |
| `ChildClosePolicy::Abandon` | Children are left to finish on their own. |

```php
$this->child('provision-billing', $customerId)
    ->closePolicy(ChildClosePolicy::Cancel)
    ->withTags(['customer' => $customerId])
    ->run();
```

## Fire and forget

```php
$started = $this->child('send-welcome-email', $customerId)
    ->closePolicy(ChildClosePolicy::Abandon)
    ->detached()
    ->run();

// ['run_id' => '01JQ…', 'detached' => true]
```

The step completes as soon as the child is created, returning its id. The parent
never waits on it and never sees its result.

Pair `detached()` with a policy other than `Fail`, or the parent may outlive a
child it never checked.

## Failure

A failed child fails the parent's child step, which unwinds the parent exactly
as any other failed step would — including the parent's own compensations:

```php
$this->action(ChargeCard::class, $id)->compensateWith(RefundCard::class, $id)->run();

$this->child('ship-order', $id)->run();   // fails
// → RefundCard runs
```

The child compensates itself first, then reports failure upward. Two levels of
rollback, each owning its own.

## Inheritance

A child inherits the parent's queue connection, queue name, and **version**, so
a versioned run stays on the same code path all the way down.

## Querying

```php
Run::query()->where('parent_run_id', $parent->id)->get();

Impex::query()->whereParent($parent->id)->handles();
```

```
GET impex/runs?parent=01JQ7X…
```
