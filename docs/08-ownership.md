# Ownership

**No user, team, or customer tables ship with this package.** The host
application decides what those are, and the hierarchy between them lives there.
A run's owners are polymorphic, with a role.

```php
Impex::run('extract-products', [$query], owners: [
    'customer' => $customer,
    'team' => $team,
    'user' => $user,
]);
```

```php
Impex::addOwner($run, $customer, 'customer');
```

The role is free-form — `customer`, `team`, `user`, `viewer`, or anything your
domain calls it.

## Querying

```php
Run::query()->whereOwnedBy($customer)->active()->get();
Run::query()->whereOwnedBy($user, 'user')->get();          // restricted to a role
Run::query()->whereOwnedByAny([$team, $user])->get();
```

## Your model

```php
namespace App\Models;

use JayI\Impex\Models\Run;

class Customer extends Model
{
    public function impexRuns()
    {
        return Run::query()->whereOwnedBy($this);
    }
}
```

## Hierarchies

Your customers have teams and your teams have users. Impex does not know that,
and should not — the moment it does, it has your schema baked in. Instead the
expansion is yours:

```php
namespace App\Impex;

final class OwnerScope
{
    /**
     * Every model whose runs this customer should be able to see.
     *
     * @return array<int, \Illuminate\Database\Eloquent\Model>
     */
    public function expand(Customer $customer): array
    {
        return [
            $customer,
            ...$customer->teams,
            ...$customer->teams->flatMap->users,
        ];
    }
}
```

```php
Run::query()->whereOwnedByAny(app(OwnerScope::class)->expand($customer))->get();
```

## Authorizing the API

Impex registers a policy for each of its models from `impex.policies`, and the
JSON API and MCP tools check every call against them. This is on by default;
turn it off only for a trusted operator surface, where the route middleware is
the only check:

```php
// config/impex.php
'authorization' => false,
```

With it on, every call acts as the authenticated user:

- A guest is refused (`403` over HTTP, `Unauthorized.` over MCP).
- `GET impex/runs` and `list-runs` return only the runs the user owns, in any
  role; `GET impex/messages` and `list-messages` only the messages of those
  runs. Your own `owner_type`/`owner_id` filters narrow that further.
- Starting a flow attaches the user to the new run in the `owner` role. A
  reused idempotency key that names someone else's run is refused.
- Every other call is checked against the policy of the model it touches.

### The bundled policies

```php
'policies' => [
    Run::class => \JayI\Impex\Policies\RunPolicy::class,
    RunStep::class => \JayI\Impex\Policies\RunStepPolicy::class,
    RunOwner::class => \JayI\Impex\Policies\RunOwnerPolicy::class,
    Signal::class => \JayI\Impex\Policies\SignalPolicy::class,
    Timer::class => \JayI\Impex\Policies\TimerPolicy::class,
    Batch::class => \JayI\Impex\Policies\BatchPolicy::class,
    BatchItem::class => \JayI\Impex\Policies\BatchItemPolicy::class,
    Message::class => \JayI\Impex\Policies\MessagePolicy::class,
    Artifact::class => \JayI\Impex\Policies\ArtifactPolicy::class,
    FlowOverride::class => \JayI\Impex\Policies\FlowOverridePolicy::class,
],
```

- **`RunPolicy`**: a run's owners — any model attached to it, in any role — may
  do anything with it, including any ability your application invents. Impex
  has no roles of its own, so everyone else is denied. Anyone signed in passes
  `viewAny` and `create`; `create` receives the flow slug, so your policy can
  limit which flows a user may start.
- **`RunStepPolicy`**, **`TimerPolicy`**, **`BatchPolicy`**: reading needs
  `view` on the run. They are engine state, so no ability changes them.
- **`BatchItemPolicy`**: reading needs `view` on the batch.
- **`RunOwnerPolicy`**: listing owners needs `view` on the run. Attaching or
  detaching one needs `share`.
- **`SignalPolicy`**: reading needs `view` on the run; delivering one needs
  `signal`.
- **`MessagePolicy`**: a message follows its run. One with no run has no owner,
  so only an operator with authorization off can read it.
- **`ArtifactPolicy`**: reading needs `view` on the artifact's run.
- **`FlowOverridePolicy`**: anyone signed in may read the flow catalogue.
  Changing a flow is left to the dashboard, behind Atrium's gate.

The child policies ask the Gate about the run, so they follow whichever run
policy is registered.

### What each call checks

| HTTP | MCP tool | Ability |
|---|---|---|
| `GET flows` | `list-flows` | `viewAny` on `FlowOverride` |
| `POST flows/{flow}/runs` | `run-flow` | `create` on `Run`, with the slug |
| `GET runs` | `list-runs` | `viewAny` on `Run` |
| `GET runs/{run}` | `show-run` | `view` on the run |
| `POST runs/{run}/cancel` | `cancel-run` | `cancel` on the run |
| `POST runs/{run}/retry` | `retry-run` | `retry` on the run |
| `GET runs/{run}/steps` | `list-run-steps` | `viewAny` on `RunStep`, with the run |
| `POST runs/{run}/signals` | `signal-run` | `create` on `Signal`, with the run |
| `GET runs/{run}/owners` | `list-run-owners` | `viewAny` on `RunOwner`, with the run |
| `POST runs/{run}/owners` | `attach-run-owner` | `create` on `RunOwner`, with the run |
| `DELETE runs/{run}/owners/{owner}` | `detach-run-owner` | `delete` on the owner record |
| `GET messages` | `list-messages` | `viewAny` on `Message` |
| `GET messages/{message}` | `show-message` | `view` on the message |
| `GET channels` | `list-channels` | signed in only — channels are config, not a model |

**The inbound channel endpoints are never user-authorized.** `POST
channels/{channel}` authenticates each request with the channel's signing
secret: an upstream has no user, so a policy there would lock out the senders
the endpoint exists to receive.

The dashboard is not covered either: Atrium owns its middleware and gate.

### Replacing a policy

- **Per ability:** extend a bundled policy and override the method for that
  ability. Point its model at your class in `impex.policies`.
- **Whole policy:** point the model at your own class:

  ```php
  'policies' => [
      Run::class => App\Policies\RunPolicy::class,
      // ...
  ],
  ```

A customer that should see every run its teams own is a `RunPolicy` whose
`view` expands the user the way the `OwnerScope` above does. Listings are
scoped to direct ownership, so pair it with an `owner_type`/`owner_id` filter
or your own endpoint.

## Over the API

```
GET    impex/runs?owner_type=App%5CModels%5CCustomer&owner_id=42
GET    impex/runs/{run}/owners
POST   impex/runs/{run}/owners      { "owner_type": "...", "owner_id": "42", "role": "customer" }
DELETE impex/runs/{run}/owners/{owner}
```

`{owner}` is the **owner record id**, from the index endpoint — not the model's
key. The morph triple is not addressable on its own, which is why the pivot
carries a surrogate key.
