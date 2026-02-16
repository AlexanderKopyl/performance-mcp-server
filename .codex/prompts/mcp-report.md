# MCP Report (manual NDJSON)

Use this to run `report.export` and capture output file paths.

## Placeholders

- `$SNAPSHOT_ID` (required)
- `$TOP_N` (optional, default `5`)

Optional:
- same `thresholds` shape as `analysis.run`

## Copy/paste

```bash
SNAPSHOT_ID="${SNAPSHOT_ID:-$(cat /tmp/mcp.snapshot_id 2>/dev/null)}"
TOP_N="${TOP_N:-5}"

read -r -d '' MCP_REQ <<'JSON'
{"jsonrpc":"2.0","id":"report-1","method":"report.export","params":{"correlation_id":"corr-report-001","normalized_snapshot_id":"__SNAPSHOT_ID__","top_n":__TOP_N__}}
JSON

MCP_REQ="${MCP_REQ/__SNAPSHOT_ID__/$SNAPSHOT_ID}"
MCP_REQ="${MCP_REQ/__TOP_N__/$TOP_N}"

printf '%s\n' "$MCP_REQ" | php bin/console app:mcp:server:run 2> >(tee -a /tmp/mcp.stderr >&2) | tee /tmp/mcp.report.json

echo "markdown_path: $(jq -r '.result.markdown_path // empty' /tmp/mcp.report.json)"
echo "json_path: $(jq -r '.result.json_path // empty' /tmp/mcp.report.json)"
```
