# Codex Local Setup

Use this repository-local Codex home so all teammates share the same prompts/config without committing personal auth/session files.

## 1) Enable project-local CODEX_HOME

Run from repository root:

```bash
export CODEX_HOME="$PWD/.codex"
```

Optional quick check:

```bash
CODEX_HOME="$PWD/.codex" codex --help
```

## 2) MCP server registration and compatibility

The file `.codex/config.toml` registers:

- command: `php`
- args: `bin/console app:mcp:server:run`

Current server behavior is JSON-RPC over newline-delimited JSON (one request per line), not MCP tool-discovery/call protocol. That means Codex MCP integration may not list callable tools even though a server entry exists.

If MCP listing works in your Codex build, check with:

```bash
CODEX_HOME="$PWD/.codex" codex mcp list --json
```

Supported baseline: manual NDJSON piping to `php bin/console app:mcp:server:run` using prompts in `.codex/prompts/`.

## 3) Happy path flow

1. `collect.run` (optional) to build a bundle.
2. `artifacts.ingest` to create `normalized_snapshot_id`.
3. `analysis.run` for findings and threshold evaluation.
4. `report.export` to write Markdown/JSON report files.

Prompt files:

- `.codex/prompts/mcp-collect.md`
- `.codex/prompts/mcp-ingest.md`
- `.codex/prompts/mcp-analyze.md`
- `.codex/prompts/mcp-report.md`

## 4) Artifacts, reports, and logs

Runtime outputs under `var/mcp`:

- snapshots: `var/mcp/snapshots`
- reports: `var/mcp/reports`
- bundles: `var/mcp/bundles`
- indexes: `var/mcp/indexes`

Server observability logs are written to `STDERR`. JSON responses are written to `STDOUT`.
