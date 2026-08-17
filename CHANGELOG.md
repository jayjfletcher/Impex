# Release Notes

## [Unreleased](https://github.com/jayi/impex/compare/v0.1.0...1.x)

### Added

- Replay engine: deterministic `handle()` replay, lease-before-execute step
  claiming, compensation, `parallel()`, `sideEffect()`, signals and timers
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

### Fixed

- Signalling a finished run now throws `CannotSignalTerminalRunException`
  (409 over HTTP) instead of silently writing a row nothing would consume.
- A timed out signal wait is recorded as skipped rather than completed with
  null, so a genuine null payload is distinguishable from nobody answering.

- Flow registry precedence no longer depends on boot order. Application config
  always wins over a package's runtime registration; previously whichever
  happened to be read first won.
- Two packages registering the same flow slug now raises
  `FlowCollisionException` instead of the second silently shadowing the first.


## [v0.1.0](https://github.com/jayi/impex/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
