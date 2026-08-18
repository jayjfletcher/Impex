# Configuration

Every key in `config/impex.php`.

## `flows`

```php
'flows' => [
    'extract-products' => \App\Flows\ExtractProductsFlow::class,
],
```

String keys set the slug; unkeyed entries derive one from the class name. Always
wins over a package's runtime registration — see [Extending](12-extending.md).

## `schedule`

```php
'schedule' => ['extract-products' => '0 * * * *'],
```

Cron expressions keyed by slug. A `schedule` value on the flow's `impex_flows`
row takes precedence, so the dashboard can reschedule without a deploy.

## `queue`

| Key | Default | Meaning |
|---|---|---|
| `connection` | `null` | Queue connection for engine jobs. `null` uses the app default. |
| `queue` | `null` | Queue name. |
| `after_commit` | `true` | Dispatch after the database transaction commits. |

Jobs carry ULIDs only, never payloads, so a message can never approach SQS's
256KB limit however large a run's data is.

## `limits`

| Key | Default | Meaning |
|---|---|---|
| `max_step_seconds` | `840` | The working window for a step. Keep below the queue worker's timeout — 900s on Lambda. |
| `resume_margin_seconds` | `30` | Headroom before that window, at which `shouldYield()` flips. |
| `lease_seconds` | `900` | How long a claimed step is owned before another invocation may reclaim it. **Must exceed `max_step_seconds`.** |
| `lock_seconds` | `120` | TTL of the per-run drive lock. |
| `fan_out_max` | `100` | Item ceiling for `fanOut()`. Above it, use `batch()`. |
| `max_resumptions` | `10000` | How many times one step may checkpoint before failing. |
| `sync_seconds` | `15` | Budget for `Impex::runSync()` and for an API caller passing `wait: true`. |

If `lease_seconds` drops below `max_step_seconds`, a legitimately slow step has
its lease reclaimed while still working, and runs twice.

## `deadlines`

| Key | Default | Meaning |
|---|---|---|
| `run` | `null` | Default seconds before a run passes its deadline and compensates. |
| `step` | `null` | Default seconds before a step passes its deadline and fails. |

Null means no deadline. Enforced by `impex:tick`, not in-process: a step that
has handed control to an upstream call cannot check a clock. Per-run and
per-step values override these.

## `artifacts`

| Key | Default | Meaning |
|---|---|---|
| `disk` | `null` | Flysystem disk for payloads. Point at S3 on Vapor. `null` uses the app default. |
| `path` | `'impex'` | Path prefix on that disk. |
| `inline_threshold` | `65536` | Bytes above which a payload goes to the disk instead of a column. |
| `stream_threshold` | `8388608` | Bytes above which scratch work streams rather than buffering. |

## `cache`

| Key | Default | Meaning |
|---|---|---|
| `store` | `null` | Store backing run locks. Must be **shared and lock-capable** — Redis or DynamoDB on Vapor. |

Lambda shares no memory between invocations, so this is what serialises
concurrent drives of one run. A store that cannot take atomic locks raises an
exception naming it.

## `timers`

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `true` | Whether the package registers `impex:tick` on the schedule. |
| `max_queue_delay` | `900` | Below this, a native queue delay is used instead of a timer row. |
| `claim_seconds` | `300` | How long a sweep's claim is held before the timer becomes claimable again. |
| `batch` | `250` | Timers fired per sweep. |

## `messages`

| Key | Default | Meaning |
|---|---|---|
| `preview_bytes` | `2048` | How much of a body is kept inline for list views. The full body always lives on the artifact disk. |

## `retention`

| Key | Default |
|---|---|
| `completed_runs_days` | `90` |
| `failed_runs_days` | `365` |
| `messages_days` | `90` |
| `artifacts_days` | `365` |

**Artifacts must never expire before the rows referencing them**, or a failed
run loses the payloads you would open it to read. Keep `artifacts_days` at or
above the longest of the others. `impex:prune` deletes in dependency order.

## `routes`

| Key | Default |
|---|---|
| `enabled` | `true` |
| `prefix` | `'impex'` |
| `middleware` | `['api']` |

Add authentication before exposing these.

## `mcp`

```php
'mcp' => [
    'web' => ['enabled' => false, 'route' => 'mcp/impex', 'middleware' => []],
    'local' => ['enabled' => false, 'handle' => 'impex'],
],
```

Both ship disabled.

## `ui`

```php
'ui' => [
    'enabled' => false,
    'path' => 'impex/ui',
    'middleware' => ['web'],
    'auth' => [
        'mode' => 'session',          // session | token | oauth | custom
        'token_resolver' => null,
        'oauth' => [
            'client_id' => null,
            'authorize_url' => '/oauth/authorize',
            'token_url' => '/oauth/token',
            'scopes' => [],
        ],
    ],
],
```

See [Dashboard](11-dashboard.md).

## `channels`

```php
'channels' => [
    'supplier-feed' => [
        'direction' => 'inbound',
        'signing_secret' => env('IMPEX_SUPPLIER_SECRET'),
        'signature_header' => 'X-Signature',
        'signature_validator' => HmacSha256Validator::class,
        'profile' => ProcessEverything::class,
        'idempotency_header' => 'X-Request-Id',
        'flow' => 'extract-products',
        'store_headers' => ['content-type'],
        'queue' => 'impex-ingest',
        'path' => null,
    ],
],
```

See [The ledger](07-ledger.md).
