# Release Notes

## [Unreleased](https://github.com/jayi/impex/compare/v0.1.0...1.x)

### Added

- Cortex integration: when `jayi/cortex` is installed, the MCP server registers with it as `impex` and every tool joins its tool registry, so agents can use them. Published instruction and tool description overrides are served to MCP clients and agents. Configured under `impex.cortex`; Cortex stays optional.
- `ImpexServer::TOOLS`, the server's tool catalog as a flat list.
- Model events: every model fires a class-based event for each Eloquent hook (`RunCreatingEvent`, `RunStepCreatedEvent`, ...), mapped by the `DispatchesModelEvents` trait.
- Action events: every action fires a start event before its work and a finish event after commit, on success (`FlowRunningActionEvent` / `FlowRanActionEvent`, ...).
- `ModelLifecycleEvent`, `ActionStartingEvent` and `ActionFinishedEvent` contracts, to listen to a whole family at once.
- Policies for every model (`RunPolicy`, `RunStepPolicy`, `RunOwnerPolicy`, `SignalPolicy`, `TimerPolicy`, `BatchPolicy`, `BatchItemPolicy`, `MessagePolicy`, `ArtifactPolicy`, `FlowOverridePolicy`), registered with the Gate from the new `impex.policies` config. A run's owners may do anything with it; child models defer to their run through the Gate, so replacing the run policy flows down.
- `impex.authorization` (on by default): every HTTP endpoint and MCP tool acts as the authenticated user, lists only the runs they own and those runs' messages, attaches them as `owner` of runs they start, and checks each call against the model's policy. Inbound channel endpoints stay signature-authenticated.
- `ListRunOwnersAction`, with `RunOwnersListingActionEvent` / `RunOwnersListedActionEvent`; the run-owner endpoint and tool now go through it.
- `ListRunsAction` and `ListMessagesAction` take an optional `$viewer`, and `RunFlowAction` an optional `$owner`; their action events carry them.
- Replay engine: deterministic `handle()` replay, lease-before-execute step
  claiming, rollback, `parallel()`, `sideEffect()`, signals and timers
- Resume protocol (`ResumableAction`) so a step can span more invocations than
  the platform's execution ceiling allows, checkpointing before it is killed
- `fanOut()` with a collection fingerprint, optional `keyBy()`, and a
  configurable cap; `batch()` for unbounded work, one history step whatever the
  item count
- Artifact offload for payloads above the inline threshold
- Flow registry with `impex_flows` runtime overrides for enable/disable and
  schedule
- Messages ledger with inbound channels (signature validation, profiles,
  idempotency) and an outbound HTTP recorder
- Polymorphic run owners, with no user/team/customer tables shipped
- HTTP API over the Action + `persist()` layering
- Commands: `impex:tick`, `impex:run`, `impex:prune`
- MCP server with fourteen tools at parity with the HTTP API, enforced by an
  arch test with a declared-exceptions list
- Vue 3 dashboard, prebuilt into `public/`, with session, token, oauth (PKCE)
  and custom auth modes
- Bundled Boost skill with determinism and serverless references
- Full documentation set under `docs/`

- Signal builder: `signal($name)->timeoutAfter()->default()->orFail()->wait()`
- `Impex::signalIfRunning()`, the `signalable()` scope, and `impex:signal`

- Child workflows: `child()` with `closePolicy()`, `detached()` and `withTags()`
- Versioning: a `VERSION` constant per flow, `$this->version()` to branch on it,
  and a guard that refuses a slug repointed at a different class
- Deadlines on runs and steps, enforced by `impex:tick`
- `Impex::runSync()`, and `wait: true` on the trigger endpoint and MCP tool
- `unit()` groups with `onRollbackFailure()` and `rollbackTogether()`
- `optionalAction()`
- `Impex::query()` with `handles()`, and `Impex::handle()`
- `JayI\Impex\Testing\Flows` assertion helpers

### Changed

- Requires `laravel/framework` instead of `illuminate/support`, since the package uses form requests, events, queues and jobs from the framework.
- Renamed the rollback vocabulary away from saga jargon: `saga()` is now
  `unit()`, `compensateWith()` is `undoWith()`, `CompensationFailure` is
  `RollbackFailure` (with `Halt` in place of `Stop`), the compensation phase is
  the rollback phase, and `RunStatus::Compensating` is `RollingBack`.
- Broke the engine into collaborators resolved from the container —
  `EngineOptions`, `JobRouter`, `StepWriter`, `Rollbacks`, `Children`, `Waits`
  and `Sweeper` — so an application can replace one without forking. `Engine`
  drops from 1,282 lines to ~720 and is now a facade over them.
- `RollbackStrategy` is a contract, so unwind order and policy are swappable.
- `impex:tick` is presentation only; the passes live in `Sweeper` and return a
  `SweepReport` you can call from a job or a health check.
- `EngineOptions` validates that `lease_seconds` exceeds `max_step_seconds`,
  which previously would have surfaced as a slow step running twice.

### Removed

- The TypeScript SDK (`sdk/`), its npm workspace and `sdk:generate`/`sdk:build` scripts, and the `dedoc/scramble` dev dependency that exported its OpenAPI spec.
- The scaffold's `impex:placeholder` command and placeholder translation file.
- The empty `impex-assets` publish tag: the dashboard's assets belong to Atrium (`atrium-assets`).
- The `impex.queue.after_commit` and `impex.artifacts.stream_threshold` config keys, which nothing read.

### Fixed

- `config/impex.php` now lists `limits.resume_margin_seconds`, `limits.max_resumptions` and `timers.enabled`, which the engine already read with those defaults.
- The MCP server instructions and docs name the tools as they are served (`run-flow-tool`, ...), behind `search_tools` / `execute_tools`.
- Signalling a finished run now throws `CannotSignalTerminalRunException`
  (409 over HTTP) instead of silently writing a row nothing would consume.
- A timed out signal wait is recorded as skipped rather than completed with
  null, so a genuine null payload is distinguishable from nobody answering.
- `expiresAt()` was recorded but never enforced, so a step deadline silently did
  nothing. Deadlines are now swept by `impex:tick`.
- `flow_version` was never written, so the divergence detection the docs
  described did not exist.
- `impex.limits.sync_seconds` was configuration with no code behind it.
- A failed rollback re-selected the same target on the next drive, looping
  the rollback forever. It now halts or skips according to the group's policy.

- Flow registry precedence no longer depends on boot order. Application config
  always wins over a package's runtime registration; previously whichever
  happened to be read first won.
- Two packages registering the same flow slug now raises
  `FlowCollisionException` instead of the second silently shadowing the first.


## [v0.1.0](https://github.com/jayi/impex/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
