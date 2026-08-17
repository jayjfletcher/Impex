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

The package does not filter runs by the authenticated user — it has no idea what
your users are. Do it in middleware or a policy:

```php
// config/impex.php
'routes' => [
    'middleware' => ['api', 'auth:api', \App\Http\Middleware\ScopeImpexToCustomer::class],
],
```

```php
final class ScopeImpexToCustomer
{
    public function handle(Request $request, Closure $next)
    {
        // Force the owner filter to the authenticated customer, whatever the
        // caller asked for.
        $request->merge([
            'owner_type' => $request->user()->customer->getMorphClass(),
            'owner_id' => (string) $request->user()->customer->getKey(),
        ]);

        return $next($request);
    }
}
```

For single-run endpoints, a policy on `Run` combined with `can:` middleware is
the ordinary Laravel path.

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
