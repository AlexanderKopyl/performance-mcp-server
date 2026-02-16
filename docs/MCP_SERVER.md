# MCP Server (STDIO)

This document describes the current MCP runtime behavior implemented in this repository.

## Scope

- CLI-only, long-running process: `app:mcp:server:run`
- Transport: STDIO
- Input: offline artifacts (SPX, MySQL slow log, timings)
- Output: normalized snapshots, analysis payloads, Markdown/JSON reports, optional collector bundles

## Quickstart: Copy/Paste Examples

Replace placeholders before running:
- `/ABS/PATH/...` with real absolute paths
- `SNAPSHOT_ID_FROM_INGEST` with a real `normalized_snapshot_id`
- `https://example.local` with a reachable non-production base URL

### Makefile shortcuts

```bash
make collect BASE_URL=https://example.local SPX_DIRS="/ABS/PATH/spx-dir" SLOW_LOG=/ABS/PATH/slow.log URL_PATHS="/"
make ingest BUNDLE_DIR=var/mcp/bundles/bundle_YYYYMMDD_HHMMSS_xxx
make analyze
make report
```

Notes:
- `make ingest` stores `result.normalized_snapshot_id` in `/tmp/mcp.snapshot_id`.
- `make analyze` and `make report` read `SNAPSHOT_ID` from env var first, then from `/tmp/mcp.snapshot_id`.

### One-shot helper (single request + stderr log capture)

```bash
run_mcp_once() {
  local payload="$1"
  printf '%s\n' "$payload" \
    | php bin/console app:mcp:server:run \
    2> >(tee -a /tmp/mcp_server.stderr.log >&2)
}
```

### Long-running pattern (multiple requests in one session)

```bash
php bin/console app:mcp:server:run
```

Then paste one JSON request per line into the same terminal session. Responses are written to `STDOUT`; logs are written to `STDERR`.

### `artifacts.validate`

```bash
run_mcp_once '{"jsonrpc":"2.0","id":"val-quick-1","method":"artifacts.validate","params":{"correlation_id":"corr-quick-val-001","artifacts":[{"path":"/ABS/PATH/spx-full-20260216-host-123-runA.json"},{"path":"/ABS/PATH/spx-full-20260216-host-123-runA.txt.gz"},{"path":"/ABS/PATH/slow.log"},{"path":"/ABS/PATH/timings.json"}]}}'
```

### `artifacts.ingest`

```bash
run_mcp_once '{"jsonrpc":"2.0","id":"ing-quick-1","method":"artifacts.ingest","params":{"correlation_id":"corr-quick-ing-001","artifacts":[{"path":"/ABS/PATH/spx-full-20260216-host-123-runA.json"},{"path":"/ABS/PATH/spx-full-20260216-host-123-runA.txt.gz"},{"path":"/ABS/PATH/slow.log"},{"path":"/ABS/PATH/timings.json"}],"environment_hints":{"env":"local","app":"example-app"}}}'
```

### `analysis.run`

```bash
run_mcp_once '{"jsonrpc":"2.0","id":"ana-quick-1","method":"analysis.run","params":{"correlation_id":"corr-quick-ana-001","normalized_snapshot_id":"SNAPSHOT_ID_FROM_INGEST","top_n":5}}'
```

### `analysis.run` with threshold overrides

```bash
run_mcp_once '{"jsonrpc":"2.0","id":"ana-quick-2","method":"analysis.run","params":{"correlation_id":"corr-quick-ana-002","normalized_snapshot_id":"SNAPSHOT_ID_FROM_INGEST","top_n":5,"thresholds":{"endpoint_ttfb_ms":{"P0":1200,"P1":700,"P2":250},"endpoint_wall_ms":{"P0":1800,"P1":900,"P2":350},"query_total_time_ms":{"P0":8000,"P1":2500,"P2":900},"span_self_ms":{"P0":700,"P1":280,"P2":90},"span_total_ms":{"P0":1200,"P1":600,"P2":220}}}}'
```

### `report.export`

```bash
run_mcp_once '{"jsonrpc":"2.0","id":"rep-quick-1","method":"report.export","params":{"correlation_id":"corr-quick-rep-001","normalized_snapshot_id":"SNAPSHOT_ID_FROM_INGEST","top_n":5}}'
```

### `collect.run` (implemented, optional flow)

```bash
run_mcp_once '{"jsonrpc":"2.0","id":"col-quick-1","method":"collect.run","params":{"correlation_id":"corr-quick-col-001","spx_dirs":["/ABS/PATH/spx-dir"],"slow_log_path":"/ABS/PATH/slow.log","base_url":"https://example.local","url_paths":["/health","/api/example"],"headers_allowlist":["x-request-id"],"headers":{"x-request-id":"mcp-demo-001"},"sample_count":3,"concurrency":1,"timeout_ms":3000,"warmup_count":1,"redaction_rules":{"strip_query_params":true,"redact_url_userinfo":true}}}'
```

Correlation note:
- Set `params.correlation_id` explicitly in each request for traceability.
- The same value appears in error responses and structured `STDERR` logs (`correlation_id` field).

## Run the Server

```bash
php bin/console app:mcp:server:run
```

Runtime loop behavior:
- reads `STDIN` line-by-line (`fgets`)
- trims line, ignores blank lines
- deserializes each non-empty line as one JSON request
- writes one JSON response line to `STDOUT`
- writes structured observability logs to `STDERR`

## STDIO Message Framing

Current framing is newline-delimited JSON (NDJSON-style):
- one complete JSON request object per line on `STDIN`
- one complete JSON response object per line on `STDOUT`
- no multi-line framing protocol is implemented
- if a request is split across lines (for example, multiline JSON or a heredoc that emits multiple lines), deserialization fails with `Malformed JSON payload.`

If a client sends malformed JSON, the server replies with an error envelope (`INVALID_REQUEST`) when possible.

## Request Envelope

Current request shape:

```json
{
  "jsonrpc": "2.0",
  "id": "req-123",
  "method": "artifacts.validate",
  "params": {
    "correlation_id": "corr-abc-001"
  }
}
```

Field behavior:
- `id`: optional, `string|int|null`
- `method`: required non-empty string (tool name)
- `params`: object (required to be JSON object if present)
- `params.correlation_id`: optional; if omitted, server derives deterministic hash-based correlation id

Notes:
- `jsonrpc` is not validated by the deserializer, but responses include `"jsonrpc":"2.0"`.
- Correlation id fallback is deterministic from request payload/raw message hash.

## Response Envelope

Success shape:

```json
{
  "jsonrpc": "2.0",
  "id": "req-123",
  "result": {}
}
```

Error shape:

```json
{
  "jsonrpc": "2.0",
  "id": "req-123",
  "error": {
    "code": "INVALID_REQUEST",
    "message": "...",
    "correlation_id": "corr-abc-001",
    "details": {}
  }
}
```

Correlation/observability fields:
- `error.correlation_id` is always present for errors
- successful tool responses include `result.correlation_id` for:
  - `analysis.run`
  - `report.export`
  - `collect.run`
- `duration_ms` is currently **not** in response payloads
- `duration_ms` is logged in structured logs (`STDERR`) per request

## Tool Catalog

### Implemented

1. `artifacts.validate`
- Purpose: validate artifact files and detect parser type/version.
- Required params:
  - `artifacts`: non-empty list of `{ path: string, hints?: object }`
- Result fields:
  - `results[]`: validation items (`path`, `ok`, `detected_type`, `detected_version`, `errors[]`, `metadata{}`)
  - `counts.ok`, `counts.failed`

2. `artifacts.ingest`
- Purpose: strict-validate, parse, normalize, persist snapshot.
- Required params:
  - `artifacts`: non-empty list of `{ path: string, hints?: object }`
- Optional params:
  - `environment_hints`: object
- Result fields:
  - `normalized_snapshot_id`
  - `counts.endpoints`, `counts.queries`, `counts.spans`
  - `sources[]` (`path`, `type`, `version`, `sha256`, `size_bytes`, `hints`)

3. `analysis.run`
- Purpose: run ranking/threshold analysis over one snapshot.
- Required params:
  - `normalized_snapshot_id` (or `snapshot_id` alias)
- Optional params:
  - `top_n` (int, clamped to 1..20, default 5)
  - `thresholds` (object, optional)
    - Supported metric keys: `endpoint_ttfb_ms`, `endpoint_wall_ms`, `query_total_time_ms`, `span_self_ms`, `span_total_ms`
    - Each metric value must be an object: `{ "P0": int, "P1": int, "P2": int }` (milliseconds)
    - Validation rules: positive integers only, and `P0 >= P1 >= P2`
    - If omitted: conservative defaults are used and threshold-focused `open_questions` are returned
    - If provided: defaults are merged with provided metric overrides; threshold-focused `open_questions` are not returned
- Result fields:
  - `normalized_snapshot_id`, `summary`, `ranking_thresholds`, `open_questions`
  - `aggregates`, `findings`, `findings_by_severity`, `correlation_id`

4. `report.export`
- Purpose: run analysis and write report Markdown + JSON under `var/mcp/reports`.
- Required params:
  - `normalized_snapshot_id` (or `snapshot_id` alias)
- Optional params:
  - same analysis params (`top_n`, `thresholds`)
- Result fields:
  - `normalized_snapshot_id`, `report_id`
  - `markdown_path`, `json_path`
  - `markdown`, `report`, `correlation_id`
  - `report.ranking_thresholds` and `report.open_questions` match `analysis.run` for the same params
  - Markdown contains `# Thresholds Used` and `# Observations` sections even when `finding_count=0`

5. `collect.run`
- Purpose: collect SPX directory files + slow log + HTTP timing probes into a bundle.
- Required params:
  - `spx_dirs[]`, `slow_log_path`, `base_url`, `url_paths[]`
- Optional params:
  - `headers`, `headers_allowlist[]`
  - `sample_count` (1..20), `concurrency` (1..4), `timeout_ms` (200..15000), `warmup_count` (0..5)
  - `redaction_rules.strip_query_params` (default `true`)
  - `redaction_rules.redact_url_userinfo` (default `true`)
  - `output_dir` (must stay inside bundles base dir)
  - `retention.keep_last_n`, `retention.ttl_days`
- Result fields:
  - `bundle_id`, `bundle_dir`, `manifest_path`, `timings_path`
  - `artifact_counts.spx_files`, `artifact_counts.inventory_items`, `artifact_counts.timing_samples`
  - `correlation_id`

### Planned / Not Currently Registered

These tools are referenced in MVP planning but are not currently registered in `ToolRouter`:
- `health.check`
- `runs.list`
- `runs.get`
- alias `ingest.bundle` (current implemented name is `artifacts.ingest`)

Current behavior if called now:
- server returns `METHOD_NOT_FOUND` (`Tool "..." is not registered.`)

`NOT_IMPLEMENTED` exists in code as a generic handler pattern but is not currently wired to any registered tool.

## Examples (One Request/Response Pair per Implemented Tool)

### 1) `artifacts.validate`

Request:

```json
{"jsonrpc":"2.0","id":"val-1","method":"artifacts.validate","params":{"correlation_id":"corr-val-001","artifacts":[{"path":"/data/spx/spx-full-20260216-web1-4242-runA.json"},{"path":"/data/spx/spx-full-20260216-web1-4242-runA.txt.gz"},{"path":"/data/mysql/slow.log"},{"path":"/data/timings/timings.json"}]}}
```

Response:

```json
{"jsonrpc":"2.0","id":"val-1","result":{"results":[{"path":"/data/spx/spx-full-20260216-web1-4242-runA.json","ok":true,"detected_type":"spx","detected_version":"spx-json-v2","errors":[],"metadata":{"pair_status":"paired"}},{"path":"/data/spx/spx-full-20260216-web1-4242-runA.txt.gz","ok":true,"detected_type":"spx","detected_version":"spx-text-gz-v1","errors":[],"metadata":{"pair_status":"paired"}},{"path":"/data/mysql/slow.log","ok":true,"detected_type":"mysql_slow_log","detected_version":"mysql-slowlog-v1","errors":[],"metadata":[]},{"path":"/data/timings/timings.json","ok":true,"detected_type":"ttfb_timings","detected_version":"collector-v1","errors":[],"metadata":[]}],"counts":{"ok":4,"failed":0}}}
```

### 2) `artifacts.ingest`

Request:

```json
{"jsonrpc":"2.0","id":"ing-1","method":"artifacts.ingest","params":{"correlation_id":"corr-ing-001","artifacts":[{"path":"/data/spx/spx-full-20260216-web1-4242-runA.json"},{"path":"/data/spx/spx-full-20260216-web1-4242-runA.txt.gz"},{"path":"/data/mysql/slow.log"},{"path":"/data/timings/timings.json"}],"environment_hints":{"env":"staging","app":"checkout"}}}
```

Response:

```json
{"jsonrpc":"2.0","id":"ing-1","result":{"normalized_snapshot_id":"e6b2f0d0d1b0f5c4d8a0c8a1f9d72f6a4bde9c47f6d9f1c9d5fa72b0a53c11aa","counts":{"endpoints":12,"queries":27,"spans":98},"sources":[{"path":"/data/mysql/slow.log","type":"mysql_slow_log","version":"mysql-slowlog-v1","sha256":"...","size_bytes":182044,"hints":[]},{"path":"/data/spx/spx-full-20260216-web1-4242-runA.json","type":"spx","version":"spx-json-v2","sha256":"...","size_bytes":903214,"hints":{"spx":{"pair_status":"paired"}}}]}}
```

### 3) `analysis.run`

Request:

```json
{"jsonrpc":"2.0","id":"ana-1","method":"analysis.run","params":{"correlation_id":"corr-ana-001","normalized_snapshot_id":"e6b2f0d0d1b0f5c4d8a0c8a1f9d72f6a4bde9c47f6d9f1c9d5fa72b0a53c11aa","top_n":5}}
```

Response:

```json
{"jsonrpc":"2.0","id":"ana-1","result":{"normalized_snapshot_id":"e6b2f0d0d1b0f5c4d8a0c8a1f9d72f6a4bde9c47f6d9f1c9d5fa72b0a53c11aa","summary":{"endpoint_count":12,"query_count":27,"finding_count":6,"p0_count":1,"p1_count":3,"p2_count":2,"top_n":5},"ranking_thresholds":{"endpoint_ttfb_ms":{"P0":1500,"P1":800,"P2":300,"source":"default_conservative"},"endpoint_wall_ms":{"P0":2000,"P1":1000,"P2":400,"source":"default_conservative"},"query_total_time_ms":{"P0":10000,"P1":3000,"P2":1000,"source":"default_conservative"},"span_self_ms":{"P0":800,"P1":300,"P2":100,"source":"default_conservative"},"span_total_ms":{"P0":1500,"P1":700,"P2":250,"source":"default_conservative"}},"open_questions":["OPEN_QUESTION: provide params.thresholds.endpoint_ttfb_ms as {\"P0\":int,\"P1\":int,\"P2\":int} to replace conservative defaults.","OPEN_QUESTION: provide params.thresholds.endpoint_wall_ms as {\"P0\":int,\"P1\":int,\"P2\":int} to replace conservative defaults.","OPEN_QUESTION: provide params.thresholds.query_total_time_ms as {\"P0\":int,\"P1\":int,\"P2\":int} to replace conservative defaults.","OPEN_QUESTION: provide params.thresholds.span_self_ms as {\"P0\":int,\"P1\":int,\"P2\":int} to replace conservative defaults.","OPEN_QUESTION: provide params.thresholds.span_total_ms as {\"P0\":int,\"P1\":int,\"P2\":int} to replace conservative defaults."],"aggregates":{},"findings":[],"findings_by_severity":{"P0":[],"P1":[],"P2":[]},"correlation_id":"corr-ana-001"}}
```

### 4) `report.export`

Request:

```json
{"jsonrpc":"2.0","id":"rep-1","method":"report.export","params":{"correlation_id":"corr-rep-001","normalized_snapshot_id":"e6b2f0d0d1b0f5c4d8a0c8a1f9d72f6a4bde9c47f6d9f1c9d5fa72b0a53c11aa","top_n":5}}
```

Response:

```json
{"jsonrpc":"2.0","id":"rep-1","result":{"normalized_snapshot_id":"e6b2f0d0d1b0f5c4d8a0c8a1f9d72f6a4bde9c47f6d9f1c9d5fa72b0a53c11aa","report_id":"7f1c5a71ab8e4d02","markdown_path":"/Users/aleksandrkopyl/www/mcp-perf-server/var/mcp/reports/report_7f1c5a71ab8e4d02.md","json_path":"/Users/aleksandrkopyl/www/mcp-perf-server/var/mcp/reports/report_7f1c5a71ab8e4d02.json","markdown":"# Executive Summary\n...","report":{"snapshot_id":"e6b2f0..."},"correlation_id":"corr-rep-001"}}
```

### 5) `collect.run`

Request:

```json
{"jsonrpc":"2.0","id":"col-1","method":"collect.run","params":{"correlation_id":"corr-col-001","spx_dirs":["/data/spx"],"slow_log_path":"/data/mysql/slow.log","base_url":"https://staging.example.internal","url_paths":["/health","/api/orders?customer_id=REDACTED"],"headers_allowlist":["x-request-id"],"headers":{"x-request-id":"demo-run-123","authorization":"REDACTED"},"sample_count":3,"concurrency":1,"timeout_ms":3000,"warmup_count":1,"redaction_rules":{"strip_query_params":true,"redact_url_userinfo":true},"retention":{"keep_last_n":20}}}
```

Response:

```json
{"jsonrpc":"2.0","id":"col-1","result":{"bundle_id":"bundle_20260216_102530_3a4f9b2d11","bundle_dir":"/Users/aleksandrkopyl/www/mcp-perf-server/var/mcp/bundles/bundle_20260216_102530_3a4f9b2d11","manifest_path":"/Users/aleksandrkopyl/www/mcp-perf-server/var/mcp/bundles/bundle_20260216_102530_3a4f9b2d11/manifest.json","timings_path":"/Users/aleksandrkopyl/www/mcp-perf-server/var/mcp/bundles/bundle_20260216_102530_3a4f9b2d11/timings.json","artifact_counts":{"spx_files":8,"inventory_items":9,"timing_samples":6},"correlation_id":"corr-col-001"}}
```

## Planned Tool Example (Current Behavior)

`health.check` request today returns `METHOD_NOT_FOUND` until a handler is registered.

Request:

```json
{"jsonrpc":"2.0","id":"health-1","method":"health.check","params":{"correlation_id":"corr-health-001"}}
```

Response:

```json
{"jsonrpc":"2.0","id":"health-1","error":{"code":"METHOD_NOT_FOUND","message":"Tool \"health.check\" is not registered.","correlation_id":"corr-health-001","details":{"tool":"health.check"}}}
```

## Error Model

### Currently emitted by runtime

- `INVALID_REQUEST`
  - malformed JSON, bad envelope shape, or tool-level input validation failures
  - client action: do not retry unchanged request; fix payload

- `METHOD_NOT_FOUND`
  - unknown/unregistered `method`
  - client action: do not retry until tool availability changes

- `INTERNAL_ERROR`
  - unhandled exception or runtime/tool internal failure
  - client action: retry only for transient environment issues (I/O/curl/runtime), otherwise inspect logs

### Present in code but not currently active in registered tools

- `NOT_IMPLEMENTED`
  - supported by `AbstractNotImplementedToolHandler`
  - currently no registered tool returns it

### Terminology note (`VALIDATION_ERROR`)

The requested model includes `VALIDATION_ERROR`, but current code emits `INVALID_REQUEST` for validation failures.

Planned alignment path:
- introduce/emit `VALIDATION_ERROR` for semantic input failures
- keep `INVALID_REQUEST` for malformed transport/envelope cases

## Correlation and Observability

Correlation id:
- Preferred: client sets `params.correlation_id`
- Fallback: server computes deterministic 32-char SHA-256 prefix hash

Structured log fields per request (`mcp` logger, `STDERR`):
- `timestamp`
- `level`
- `tool_name`
- `correlation_id`
- `snapshot_id` (when available from params)
- `duration_ms`
- `error_code` (nullable)

Redaction behavior:
- log keys such as `authorization`, `cookie`, `password`, `secret`, `token`, `api_key`, `set-cookie` are replaced with `[REDACTED]`
- SQL-like content in logs is literal-redacted
- collector timings default to stripping query params and URL userinfo
- slow-log SQL examples are literal-redacted before storage

## Safety and Data-Access Guarantees

- No HTTP server endpoints; transport is local STDIO only.
- Artifact source files are read-only inputs; server does not mutate source artifacts.
- Server writes generated state only under `var/mcp` (snapshots, reports, bundles, indexes).
- `collect.run` probe uses HTTP `GET` requests only.

## Operational Notes

### Storage layout

- `var/mcp/snapshots/snapshot_<normalized_snapshot_id>/snapshot.json`
- `var/mcp/snapshots/snapshot_<normalized_snapshot_id>/metadata.json`
- `var/mcp/snapshots/index.json`
- `var/mcp/reports/report_<report_id>.md`
- `var/mcp/reports/report_<report_id>.json`
- `var/mcp/bundles/<bundle_id>/manifest.json`
- `var/mcp/bundles/<bundle_id>/timings.json`

### Identifiers

- `normalized_snapshot_id`: SHA-256 over canonicalized normalized snapshot content (deterministic)
- `report_id`: 16-char SHA-256 prefix over snapshot id + report payload (deterministic)
- `bundle_id`: time/random based (not deterministic)
- `run_id`: not currently exposed as top-level MCP field; collector manifest contains `run.bundle_id`

### Retention knobs

Configured in `config/services.yaml`:
- snapshot retention:
  - `app.retention.keep_last_n` (default `50`)
  - `app.retention.ttl_days` (default `null`)
- collector bundle retention defaults:
  - `app.collector.retention.keep_last_n` (default `50`)
  - `app.collector.retention.ttl_days` (default `null`)
- collector per-request override:
  - `params.retention.keep_last_n`
  - `params.retention.ttl_days`

### Size/performance limits

- `collect.run`:
  - `sample_count` 1..20
  - `concurrency` 1..4
  - `timeout_ms` 200..15000
  - `warmup_count` 0..5
  - max files discovered per SPX dir: `app.collector.max_files_per_spx_dir` (default `500`)
  - raw artifact copy threshold: `app.collector.copy_max_bytes` (default `10 MiB`), larger files are referenced not copied
- SPX `.txt.gz` validation decompress cap: `app.spx.text_gz_max_decompressed_bytes` (default `16 MiB`)
- MySQL slow-log validation scans up to first 500 lines for required markers
- response streaming: not implemented; each request returns a single final response line

## Troubleshooting

1. Unknown artifact format
- Symptom: `artifacts.validate` returns `ok=false` with errors like `unsupported artifact format`.
- Check: file exists, readable, and matches one supported format (`spx`, `mysql_slow_log`, `ttfb_timings`).

2. Missing SPX pair metadata / partial pair
- Symptom: SPX validates but metadata indicates pair status not fully paired.
- Check: both files with shared SPX base name exist (`.json` and `.txt.gz`) when pair completeness is required by your workflow.

3. Wrong paths or permissions
- Symptom: `artifact file not found`, `cannot read file`, `SPX directory not found`, or `slow_log_path must point to a readable file`.
- Check: absolute paths, local permissions, and that process user can read inputs and write `var/mcp`.

4. Large slow log / large SPX gzip
- Symptom: slow ingestion, memory pressure, or SPX validation error `decompressed content exceeds ... bytes`.
- Check: split files, adjust configured limits carefully, and verify artifact quality before ingest.

5. Collector timing probe failures
- Symptom: `INTERNAL_ERROR` mentioning curl or per-sample `error` records.
- Check: PHP curl extension availability, reachable `base_url`, timeout/concurrency settings.

## Verification and Rollback

Verification:
1. Start server and run `artifacts.validate` on known sample files.
2. Ingest and capture `normalized_snapshot_id`.
3. Run `analysis.run` and verify `summary` + `correlation_id`.
4. Run `report.export` and verify report files exist.
5. If using collector, run `collect.run` and verify `manifest.json` and `timings.json`.

Rollback:
- Remove generated state under `var/mcp` (or subdirectories: `snapshots`, `reports`, `bundles`, `indexes`).
- Source artifact files are unchanged by server operations.
