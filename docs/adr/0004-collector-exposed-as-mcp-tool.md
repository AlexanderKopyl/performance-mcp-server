# ADR 0004: Collector Exposure = MCP Tool (`collect.run`)

- Status: Accepted
- Date: 2026-02-16

## Context
The collector assembles analysis bundles from local SPX directories, MySQL slow-log files, and safe HTTP timing probes. The runtime already uses a long-running STDIO MCP loop with tool handlers.

## Decision
Expose the collector as MCP tool `collect.run` instead of adding a standalone CLI-first collector command.

## Consequences
- Positive: Reuses the existing tool router, correlation IDs, and MCP error envelope patterns.
- Positive: Keeps collection and analysis flows in one transport (`STDIO`) for local MCP clients.
- Positive: Easy to add guardrails (timeouts, concurrency limits, redaction) in one request schema.
- Negative: Direct shell usage requires sending MCP JSON payloads instead of a single purpose CLI command.
- Negative: If future operators need cron/batch usage, a thin CLI wrapper may still be added.
