# MCP Analyze (manual NDJSON)

Use this to run `analysis.run` against a `normalized_snapshot_id`.

## Placeholders

- `$SNAPSHOT_ID` (required)

Optional:
- `$TOP_N` (default `5`)
- `thresholds` overrides

## Threshold schema

`thresholds` is an object with exactly these metric keys:

- `endpoint_ttfb_ms`
- `endpoint_wall_ms`
- `query_total_time_ms`
- `span_self_ms`
- `span_total_ms`

Each metric value must be:

```json
{"P0": 1200, "P1": 700, "P2": 250}
```

Rules:
- all values are positive integers
- `P0 >= P1 >= P2`

## Copy/paste (default thresholds)

```bash
SNAPSHOT_ID="${SNAPSHOT_ID:-$(cat /tmp/mcp.snapshot_id 2>/dev/null)}"
TOP_N="${TOP_N:-5}"

read -r -d '' MCP_REQ <<'JSON'
{"jsonrpc":"2.0","id":"analyze-1","method":"analysis.run","params":{"correlation_id":"corr-analyze-001","normalized_snapshot_id":"__SNAPSHOT_ID__","top_n":__TOP_N__}}
JSON

MCP_REQ="${MCP_REQ/__SNAPSHOT_ID__/$SNAPSHOT_ID}"
MCP_REQ="${MCP_REQ/__TOP_N__/$TOP_N}"

printf '%s\n' "$MCP_REQ" | php bin/console app:mcp:server:run 2> >(tee -a /tmp/mcp.stderr >&2) | tee /tmp/mcp.analysis.json
```

## Copy/paste (with threshold overrides)

```bash
SNAPSHOT_ID="${SNAPSHOT_ID:-$(cat /tmp/mcp.snapshot_id 2>/dev/null)}"

read -r -d '' MCP_REQ <<'JSON'
{"jsonrpc":"2.0","id":"analyze-2","method":"analysis.run","params":{"correlation_id":"corr-analyze-002","normalized_snapshot_id":"__SNAPSHOT_ID__","top_n":5,"thresholds":{"endpoint_ttfb_ms":{"P0":1200,"P1":700,"P2":250},"endpoint_wall_ms":{"P0":1800,"P1":900,"P2":350},"query_total_time_ms":{"P0":8000,"P1":2500,"P2":900},"span_self_ms":{"P0":700,"P1":280,"P2":90},"span_total_ms":{"P0":1200,"P1":600,"P2":220}}}}
JSON

MCP_REQ="${MCP_REQ/__SNAPSHOT_ID__/$SNAPSHOT_ID}"

printf '%s\n' "$MCP_REQ" | php bin/console app:mcp:server:run 2> >(tee -a /tmp/mcp.stderr >&2) | tee /tmp/mcp.analysis.json
```
