# ADR 0003: Retention = Minimal with Rotation

- Status: Accepted
- Date: 2026-02-16

## Context
Artifacts and logs can grow quickly; initial version should avoid unbounded disk usage.

## Decision
Start with minimal retention policy and file rotation strategy as baseline behavior. Fine-grained retention controls are deferred.

## Consequences
- Positive: Reduced immediate operational complexity.
- Positive: Prevents obvious disk exhaustion during early usage.
- Negative: Coarse policy may not match all workloads.
- Negative: Requires future policy knobs (time-based, size-based, per-artifact class).
