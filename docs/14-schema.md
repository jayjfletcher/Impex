# Schema

All tables are ULID-keyed with hardcoded names. Table names are **not**
configurable: the validation rules on Actions embed literal table names, and a
configurable connection would silently void every foreign key.

## `impex_runs`

One execution of a flow.

| Column | Notes |
|---|---|
| `id` | ulid pk |
| `flow` | registry slug, indexed |
| `flow_class` | recorded for divergence detection |
| `flow_version` | the version the run started under; `$this->version()` branches on it |
| `status` | see [Flows](02-flows.md#statuses) |
| `trigger` | `api` `mcp` `command` `schedule` `channel` `code` `child` |
| `idempotency_key` | **unique**; a repeat returns the original run |
| `input`, `input_artifact_id` | inline below the threshold, on the disk above it |
| `result`, `result_artifact_id` | same |
| `error` | class, message, file, line |
| `tags` | queryable json |
| `parent_run_id`, `parent_sequence` | child workflows |
| `close_policy` | what happens to this child if its parent finishes first |
| `queue_connection`, `queue` | per-run routing |
| `expires_at`, `started_at`, `finished_at` | |

Indexes: `(status, created_at)`, `(flow, status)`, `(parent_run_id)`.

## `impex_run_steps`

The replay log.

| Column | Notes |
|---|---|
| `run_id`, `phase`, `sequence` | **unique together** — the idempotency spine |
| `type` | `action` `rollback` `side_effect` `signal` `fan_out` `batch` `child` `timer` |
| `name` | action class, side-effect key, signal name |
| `status` | `pending` `running` `completed` `failed` `rolled back` `skipped` |
| `input`/`result` + artifact ids | |
| `rollback` | `{action, arguments}`, captured when the forward step is recorded |
| `rolled back` | whether the rollback has run |
| `attempts`, `max_attempts` | |
| `cursor`, `resumptions` | the resume checkpoint and how many times it fired |
| `lease_token`, `leased_until` | claim-before-execute; a lapsed lease is reclaimable |
| `undoes_sequence` | back-reference from a rollback step |
| `unit_id` | groups steps declared in one `unit()` block, so a rollback can find a step's peers and apply the group's policy |

`phase` gives rollback its own sequence space, so rollback steps never
collide with the forward history the replay reads.

## `impex_run_owners`

| Column | Notes |
|---|---|
| `id` | surrogate ulid, so the morph triple is addressable |
| `run_id`, `owner_type`, `owner_id`, `role` | **unique together** |

Sized to 26+191+64+32 chars — 1252 bytes under utf8mb4, inside InnoDB's
3072-byte key limit.

## `impex_signals`

| Column | Notes |
|---|---|
| `run_id`, `name`, `idempotency_key` | **unique together** |
| `payload`, `payload_artifact_id` | |
| `delivered_at`, `consumed_at`, `consumed_sequence` | |

A signal delivered before the run waits for it is held and consumed on arrival.

## `impex_timers`

| Column | Notes |
|---|---|
| `run_id`, `phase`, `sequence` | the step waiting on it |
| `kind` | `sleep` `signal_timeout` `run_deadline` `step_deadline` |
| `wake_at` | indexed |
| `claimed_at`, `claim_token`, `fired_at` | lease-claimed by `impex:tick` |

Why it exists: SQS caps message delay at 15 minutes.

## `impex_messages`

The ledger.

| Column | Notes |
|---|---|
| `run_id`, `step_id` | null for messages received before a run exists |
| `direction` | `inbound` `outbound` |
| `channel`, `transport`, `endpoint`, `method`, `status_code` | |
| `headers` | filtered by the channel's `store_headers` |
| `body_artifact_id`, `body_preview`, `bytes` | |
| `signature_valid` | inbound only |
| `duration_ms` | outbound only |
| `channel` + `idempotency_key` | **unique together** |
| `occurred_at` | indexed |

## `impex_artifacts`

| Column | Notes |
|---|---|
| `disk`, `path`, `mime`, `bytes`, `checksum` | |
| `kind` | `payload` `result` `image` `document` `other` |
| `run_id`, `step_id`, `message_id` | plain indexed ULIDs, **no foreign keys** |
| `expires_at` | |

Uses `Prunable`, not `MassPrunable`: mass pruning issues a bulk delete without
hydrating models, so `pruning()` never fires and every stored object would be
orphaned on the disk.

**Circular references.** Runs, steps and messages point at artifacts; artifacts
point back. Both directions cannot be constrained, so the artifact side carries
no foreign keys — it is always written first. The `*_artifact_id` columns hold
the real constraints, with `nullOnDelete`.

## `impex_flows`

Runtime overrides only. The registry decides which flows exist.

| Column | Notes |
|---|---|
| `slug` | unique; a row for an unregistered slug is inert |
| `enabled` | nullable; only an explicit `false` disables |
| `schedule` | overrides `impex.schedule` |
| `queue`, `queue_connection`, `defaults` | reserved; not read by the engine. Route a run with `impex_runs.queue_connection`/`queue` instead |

## `impex_batches` / `impex_batch_items`

Per-item state for `batch()`, deliberately **outside** the replay log.

`impex_batches`: `run_id`, `step_id`, `source`, `source_arguments`, `action`,
`chunk_size`, `allow_failures`, `max_attempts`, `seeded`, `total`, `succeeded`,
`failed`, `finalized_at`.

`impex_batch_items`: `batch_id` + `item_key` **unique together** — the item-level
equivalent of `unique(run_id, sequence)`. Plus `payload`, `result`, `error`,
`attempts`, `lease_token`, `leased_until`.

This is what keeps a million-item sweep to a two-step history.
