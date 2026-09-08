# Dashboard

Impex renders its dashboard through [Atrium](https://github.com/jayi/atrium),
which it requires. There is no separate bundle, no build step, and no auth
mode to choose: the pages are server-rendered Blade behind Atrium's gate.

## Setup

Define Atrium's gate and Impex appears in the sidebar:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewAtrium', fn ($user) => $user->is_admin);
```

Publish Atrium's assets once:

```bash
php artisan vendor:publish --tag=atrium-assets
```

Then set the single switch:

```php
// config/impex.php
'ui' => ['enabled' => true],
```

Setting it to `false` removes Impex from the dashboard and leaves the JSON API
serving. **Gate this carefully**: the dashboard renders every payload that has
crossed your application boundary.

## Screens

| Screen | What it shows |
|---|---|
| Runs | Filterable by status, flow, trigger, owner and tag. Cursor paginated. |
| Run detail | Definition list, error panel, cancel and retry, a signal form shown only while the run is waiting, the step timeline distinguishing forward from rollback, owners, and messages. |
| Messages | The ledger in both directions, filterable by direction and channel. |
| Message detail | Headers, body preview, signature status, and a link to the owning run. |
| Flows | The catalogue with a trigger form per flow. |
| Channels | Registered inbound channels and whether each verifies signatures. |

Status filters are driven from `RunStatus::cases()`, so the dashboard can never
offer a status the API would reject.

## Widgets

Impex contributes three widgets to Atrium's picker:

| Widget | Shows |
|---|---|
| Run status | How many runs sit in each state, each linking to a filtered list. |
| Recent failures | The five most recently failed runs. |
| Message volume | Inbound and outbound counts over the last day. |

These are **offered**, not placed. A widget appears on a dashboard only when
someone adds it.

## Search

Impex registers a search source with Atrium, so the command palette matches runs
by flow name or by run id prefix.
