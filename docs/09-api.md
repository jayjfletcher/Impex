# HTTP API

```php
// config/impex.php
'routes' => [
    'enabled' => true,
    'prefix' => 'impex',
    'middleware' => ['api'],
],
```

**Add authentication middleware before exposing these.** They trigger and cancel
workflows and read every payload that has crossed the boundary.

Every endpoint is one line of controller: validation rules come from an Action's
static `rules()`, and the request's `persist()` calls that same Action. The MCP
surface calls the same Actions, so the two cannot drift.

## Flows

### `GET impex/flows`

```json
{
  "data": [
    {
      "slug": "extract-products",
      "class": "App\\Flows\\ExtractProductsFlow",
      "enabled": true,
      "schedule": "0 * * * *"
    }
  ]
}
```

### `POST impex/flows/{flow}/runs`

```json
{
  "arguments": ["drill bits", 50],
  "idempotency_key": "req_9f2c",
  "tags": { "tenant": "acme" }
}
```

`202 Accepted` — the run is queued, never executed inline, so the caller is not
held past the gateway's timeout.

```json
{
  "data": {
    "id": "01JQ7X…",
    "flow": "extract-products",
    "status": "pending",
    "trigger": "api",
    "idempotency_key": "req_9f2c",
    "tags": { "tenant": "acme" },
    "error": null,
    "parent_run_id": null,
    "started_at": null,
    "finished_at": null,
    "created_at": "2026-08-17T09:14:02+00:00"
  }
}
```

Reusing an idempotency key returns the original run rather than starting a
second one. A flow disabled by an `impex_flows` override is refused.

## Runs

### `GET impex/runs`

| Filter | Notes |
|---|---|
| `status` | `pending` `running` `waiting` `compensating` `completed` `failed` `cancelled` |
| `flow` | slug |
| `trigger` | `api` `mcp` `command` `schedule` `channel` `code` `child` |
| `owner_type` + `owner_id` | both required together |
| `tag[key]=value` | repeatable |
| `since`, `until` | dates, against `created_at` |
| `parent` | a run id; returns its children |
| `cursor`, `per_page` | cursor pagination, 1–200 |

```
GET impex/runs?status=failed&flow=extract-products&tag[tenant]=acme&per_page=50
```

Cursor pagination, not offset: a ledger only grows, and deep offsets degrade.

### `GET impex/runs/{run}`

Includes `owners` and the forward `steps`.

```json
{
  "data": {
    "id": "01JQ7X…",
    "flow": "extract-products",
    "status": "completed",
    "owners": [
      { "id": "01JQ…", "owner_type": "App\\Models\\Customer", "owner_id": "42", "role": "customer" }
    ],
    "steps": [
      {
        "id": "01JQ…",
        "phase": "forward",
        "sequence": 0,
        "type": "action",
        "name": "App\\Flows\\Actions\\SearchProducts",
        "status": "completed",
        "attempts": 1,
        "max_attempts": 1,
        "resumptions": 0,
        "compensated": false,
        "compensates_sequence": null,
        "error": null,
        "has_result": true,
        "result_artifact_id": null,
        "started_at": "2026-08-17T09:14:03+00:00",
        "completed_at": "2026-08-17T09:14:05+00:00"
      }
    ]
  }
}
```

**Payloads are never inlined.** `has_result` tells you a result exists;
`result_artifact_id` points at it when it was too large for a column. A step
result can be hundreds of megabytes.

### `POST impex/runs/{run}/cancel`

```json
{ "reason": "Superseded by run 01JQ8…" }
```

A run that has already finished is returned unchanged.

### `POST impex/runs/{run}/retry`

`202`. Re-queues a drive. Completed steps are not re-executed.

## Steps

### `GET impex/runs/{run}/steps`

`?phase=forward` or `?phase=compensation`. Omit for both, ordered by phase then
sequence.

## Signals

### `POST impex/runs/{run}/signals`

```json
{
  "name": "approval",
  "payload": { "approved": true, "by": 42 },
  "idempotency_key": "evt_9f2",
  "if_running": false
}
```

`202` on delivery. A signal delivered before the run reaches its wait is held,
not lost, and is accepted by any unfinished run — `pending`, `running`, or
`waiting`.

`409` if the run has already finished: a conflict rather than a validation
error, because the request was well-formed and the run's state made it
impossible. Set `if_running: true` to get `200` with `data: null` instead.

## Owners

```
GET    impex/runs/{run}/owners
POST   impex/runs/{run}/owners
DELETE impex/runs/{run}/owners/{owner}
```

```json
{ "owner_type": "App\\Models\\Customer", "owner_id": "42", "role": "customer" }
```

`201` on create, `204` on delete. `{owner}` is the owner **record** id from the
index, not the model's key.

## Messages

### `GET impex/messages`

Filters: `direction`, `channel`, `run`, `since`, `until`, `cursor`, `per_page`.

```json
{
  "data": [
    {
      "id": "01JQ…",
      "run_id": "01JQ7X…",
      "step_id": null,
      "direction": "inbound",
      "channel": "supplier-feed",
      "transport": "http",
      "endpoint": "https://app.test/impex/channels/supplier-feed",
      "method": "POST",
      "status_code": null,
      "headers": { "content-type": ["application/json"] },
      "bytes": 2048,
      "body_preview": "{\"sku\":\"ABC-1\"…",
      "body_artifact_id": null,
      "signature_valid": true,
      "duration_ms": null,
      "error": null,
      "occurred_at": "2026-08-17T09:14:02+00:00"
    }
  ]
}
```

### `GET impex/messages/{message}`

## Channels

### `GET impex/channels`

```json
{
  "data": [
    {
      "name": "supplier-feed",
      "direction": "inbound",
      "verifies_signatures": true,
      "flow": "extract-products",
      "path": null
    }
  ]
}
```

Signing secrets are never returned.

### `POST impex/channels/{channel}`

The inbound endpoint. See [The ledger](07-ledger.md).

## Errors

Validation failures return `422` in Laravel's standard shape:

```json
{
  "message": "The selected status is invalid.",
  "errors": { "status": ["The selected status is invalid."] }
}
```

## Route names

Every route is named `impex.*` — `impex.runs.index`, `impex.flows.runs.store`,
`impex.runs.signals.store`, and so on — so you can `route()` them from your own
code.
