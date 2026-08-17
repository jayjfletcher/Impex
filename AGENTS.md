# Impex

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `jayi/impex`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
- `package-generate-skill`: use when updating the bundled Boost skill from the package implementation, README, and examples.

## Engine Invariants

These are load-bearing. Changing any of them changes correctness, not style.

- `handle()` is replayed from the top on every drive. Steps are keyed by their
  position in the replay, so the DSL call order must be deterministic. Anything
  unrecomputable goes through `sideEffect()`.
- A step is **claimed before it executes**. A unique `(run_id, phase, sequence)`
  alone does not make at-least-once delivery safe, because a record-after-execute
  step calls the upstream before it can lose an insert race.
- A lapsed lease is reclaimable **by design** — that is what stops a killed
  invocation wedging a run. The guarantee is at-most-once per lease, not
  exactly-once. Say so in docs rather than implying otherwise.
- `lease_seconds` must stay above `max_step_seconds`, or a legitimately slow
  step is reclaimed while still working and runs twice.
- Jobs carry identifiers only, never payloads. There is an arch test for this.
  Anything above the inline threshold goes to the artifact disk.
- Waits longer than the queue's delay ceiling are timer rows, never delayed
  jobs. `impex:tick` is required, not optional.
- Timer and lease claims use a lease predicate, never a bare `claimed_at IS NULL`
  — that strands the row forever if the claimer dies mid-dispatch.
- Artifacts use `Prunable`, not `MassPrunable`: mass pruning never fires
  `pruning()` and would orphan every stored object.
- Artifact retention must never be shorter than the retention of the rows
  pointing at artifacts.
- Compensation is captured when the forward step is recorded, so rollback never
  replays the flow. That is why `compensateWith()` takes a class, not a closure.
- Replay is O(history) per drive. Per-item fan-out is capped; large collections
  use `batch()`, whose per-item state lives outside the replay log.
- Table names are hardcoded, matching cortex. A configurable prefix would break
  the literal table names in the static `rules()` convention.
