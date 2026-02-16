# MCP Ingest (manual NDJSON)

Use this to run `artifacts.ingest` from the latest collected bundle or explicit file paths.

## Placeholders

- `$BUNDLE_DIR` or explicit artifact paths
- `$SPX_JSON`
- `$SPX_TXT_GZ`
- `$SLOW_LOG`
- `$TIMINGS_JSON`

## Option A: derive paths from latest bundle

```bash
BUNDLE_DIR="${BUNDLE_DIR:-$(ls -td var/mcp/bundles/* 2>/dev/null | head -n1)}"
if [ -z "$BUNDLE_DIR" ]; then
  echo "No bundle found under var/mcp/bundles" >&2
  return 1 2>/dev/null || exit 1
fi

SPX_JSON="$(find "$BUNDLE_DIR" -type f -name '*.json' | head -n1)"
SPX_TXT_GZ="$(find "$BUNDLE_DIR" -type f -name '*.txt.gz' | head -n1)"
SLOW_LOG="$(find "$BUNDLE_DIR" -type f -name '*slow*.log' | head -n1)"
TIMINGS_JSON="$(find "$BUNDLE_DIR" -type f -name '*timings*.json' | head -n1)"
```

## Option B: set explicit paths

```bash
SPX_JSON="${SPX_JSON:-/ABS/PATH/spx-full.json}"
SPX_TXT_GZ="${SPX_TXT_GZ:-/ABS/PATH/spx-full.txt.gz}"
SLOW_LOG="${SLOW_LOG:-/ABS/PATH/slow.log}"
TIMINGS_JSON="${TIMINGS_JSON:-/ABS/PATH/timings.json}"
```

## Send request

```bash
read -r -d '' MCP_REQ <<'JSON'
{"jsonrpc":"2.0","id":"ingest-1","method":"artifacts.ingest","params":{"correlation_id":"corr-ingest-001","artifacts":[{"path":"__SPX_JSON__"},{"path":"__SPX_TXT_GZ__"},{"path":"__SLOW_LOG__"},{"path":"__TIMINGS_JSON__"}],"environment_hints":{"env":"local","app":"example-app"}}}
JSON

MCP_REQ="${MCP_REQ/__SPX_JSON__/$SPX_JSON}"
MCP_REQ="${MCP_REQ/__SPX_TXT_GZ__/$SPX_TXT_GZ}"
MCP_REQ="${MCP_REQ/__SLOW_LOG__/$SLOW_LOG}"
MCP_REQ="${MCP_REQ/__TIMINGS_JSON__/$TIMINGS_JSON}"

printf '%s\n' "$MCP_REQ" | php bin/console app:mcp:server:run 2> >(tee -a /tmp/mcp.stderr >&2) | tee /tmp/mcp.ingest.json
```

Save snapshot id for next step:

```bash
jq -r '.result.normalized_snapshot_id // empty' /tmp/mcp.ingest.json | tee /tmp/mcp.snapshot_id
```
