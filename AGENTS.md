# AGENTS

## Purpose
Symfony 8 CLI MCP server for offline performance analysis over local artifacts. Runtime is STDIO, long-running, JSON requests line-by-line.

## Core Commands
- Install deps: `composer install`
- Run server loop: `php bin/console app:mcp:server:run`
- Makefile helpers:
  - `make collect` (calls `collect.run`)
  - `make ingest` (calls `artifacts.ingest`)
  - `make analyze` (calls `analysis.run`)
  - `make report` (calls `report.export`)
  - `make show` (print last outputs + stderr)

## Common Workflows
### Quick smoke test
- Start the server.
- From another terminal, send `artifacts.validate` with a known artifact path.

### End-to-end analysis (Makefile)
1. `make collect` to build a bundle (outputs to `/tmp/mcp.last.json`).
2. `make ingest` to normalize artifacts; writes `normalized_snapshot_id` to `/tmp/mcp.snapshot_id`.
3. `make analyze` uses `SNAPSHOT_ID` or `/tmp/mcp.snapshot_id`.
4. `make report` exports Markdown/JSON reports under `var/mcp/reports`.

### One-shot request helper
Use the `run_mcp_once` helper in `docs/MCP_SERVER.md` to send a single JSON request and capture stderr logs.

## Outputs + Artifacts
- Runtime outputs live under `var/mcp`:
  - snapshots: `var/mcp/snapshots`
  - reports: `var/mcp/reports`
  - bundles: `var/mcp/bundles`
  - indexes: `var/mcp/indexes`
- Makefile output files (default):
  - responses: `/tmp/mcp.last.json`, `/tmp/mcp.ingest.json`, `/tmp/mcp.analysis.json`, `/tmp/mcp.report.json`
  - snapshot id: `/tmp/mcp.snapshot_id`
  - stderr: `/tmp/mcp.stderr`

## Notes
- STDIO framing is NDJSON-style: one JSON request per line.
- `analysis.run` and `report.export` accept optional `thresholds` and `top_n` (see `docs/MCP_SERVER.md`).
- TODO: Confirm whether CI/tests exist and how they are run.
