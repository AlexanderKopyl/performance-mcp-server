# ADR 0002: Storage = Filesystem Now, DB Later

- Status: Accepted
- Date: 2026-02-16

## Context
Current scope is artifact ingestion/analysis skeleton without persistence-heavy querying requirements.

## Decision
Adopt local filesystem storage under `var/mcp` with predictable folders for snapshots and reports.

## Consequences
- Positive: Fast implementation, easy local debugging, no infrastructure dependency.
- Positive: Deterministic path-based storage behavior.
- Negative: Limited indexing/query capability.
- Negative: Future migration needed for richer filtering, retention analytics, and concurrency controls.
