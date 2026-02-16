# MCP Performance Analyzer Server

Symfony 8.0 CLI MCP server for offline performance analysis over local artifacts.

- Transport: STDIO (long-running process, JSON-RPC-like messages, one JSON message per line)
- Runtime: PHP `>=8.4` (project target uses PHP 8.5), Symfony `8.0.*`
- Storage: local filesystem under `var/mcp`

## Quickstart

1. Install dependencies:

```bash
composer install
```

2. Start the MCP server loop:

```bash
php bin/console app:mcp:server:run
```

The process stays running, reads requests from `STDIN`, and writes responses to `STDOUT`.
Structured logs are emitted to `STDERR` on the `mcp` channel.

## Minimal Request/Response Smoke Test

With the server running in one terminal, send this from another terminal:

```bash
printf '%s\n' '{"jsonrpc":"2.0","id":"req-1","method":"artifacts.validate","params":{"correlation_id":"corr-demo-001","artifacts":[{"path":"/data/spx/spx-full-20260216-host-123-abc.json"}]}}' \
| php bin/console app:mcp:server:run
```

Expected behavior:
- If the file exists and is valid: `result.results[0].ok=true`
- If not: `result.results[0].ok=false` or `error.code="INVALID_REQUEST"` for malformed params

## Implemented Tools

- `artifacts.validate`
- `artifacts.ingest`
- `analysis.run`
- `report.export`
- `collect.run`

See `docs/MCP_SERVER.md` for exact schemas and examples.

## Verification Steps

1. Start server and send `artifacts.validate` with one known-good artifact path.
2. Send `artifacts.ingest` with a valid SPX pair + slow-log + timings artifact.
3. Use returned `normalized_snapshot_id` in `analysis.run`.
4. Run `report.export` and verify `markdown_path` and `json_path` exist under `var/mcp/reports`.
5. If using collector, run `collect.run` and verify `manifest_path`/`timings_path` under `var/mcp/bundles`.

## Rollback Notes

This project is filesystem-first. Runtime outputs are under `var/mcp`:
- snapshots: `var/mcp/snapshots`
- reports: `var/mcp/reports`
- bundles: `var/mcp/bundles`
- indexes: `var/mcp/indexes`

Rollback for generated analysis state is deleting these directories or specific generated files. Source artifacts are not modified by the server.
