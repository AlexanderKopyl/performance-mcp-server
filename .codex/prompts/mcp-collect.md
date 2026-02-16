# MCP Collect (manual NDJSON)

Use this when you want to run `collect.run` directly against the local STDIO server.

## Required placeholders

- `$BASE_URL`
- `$SPX_DIR`
- `$SLOW_LOG`

## Copy/paste (macOS zsh)

```bash
BASE_URL="${BASE_URL:-https://example.local}"
SPX_DIR="${SPX_DIR:-/ABS/PATH/spx-dir}"
SLOW_LOG="${SLOW_LOG:-/ABS/PATH/slow.log}"

read -r -d '' MCP_REQ <<'JSON'
{"jsonrpc":"2.0","id":"collect-1","method":"collect.run","params":{"correlation_id":"corr-collect-001","spx_dirs":["__SPX_DIR__"],"slow_log_path":"__SLOW_LOG__","base_url":"__BASE_URL__","url_paths":["/","/health"],"headers_allowlist":["authorization","x-request-id"],"headers":{"authorization":"REDACTED","x-request-id":"codex-collect-001"},"sample_count":3,"concurrency":1,"timeout_ms":3000,"warmup_count":1,"redaction_rules":{"strip_query_params":true,"redact_url_userinfo":true}}}
JSON

MCP_REQ="${MCP_REQ/__SPX_DIR__/$SPX_DIR}"
MCP_REQ="${MCP_REQ/__SLOW_LOG__/$SLOW_LOG}"
MCP_REQ="${MCP_REQ/__BASE_URL__/$BASE_URL}"

printf '%s\n' "$MCP_REQ" | php bin/console app:mcp:server:run 2> >(tee -a /tmp/mcp.stderr >&2)
```

Response fields to keep:
- `result.bundle_dir`
- `result.manifest_path`
