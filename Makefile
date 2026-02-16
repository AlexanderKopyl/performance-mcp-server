SHELL := /bin/bash
.ONESHELL:
.DEFAULT_GOAL := help

MCP_CMD ?= php bin/console app:mcp:server:run
STDERR_LOG ?= /tmp/mcp.stderr
OUT_JSON ?= /tmp/mcp.last.json

# Debug (optional)
XDEBUG_TRIGGER ?= PHPSTORM

# Common
CORRELATION_ID ?= corr-$(shell date +%Y%m%d_%H%M%S)
TOP_N ?= 5

# collect.run
SPX_DIR ?= /tmp/spx
SLOW_LOG ?= /opt/homebrew/var/mysql/mysql-slow.log
BASE_URL ?= https://zoocomplex.com.ua
URL_PATH ?= /
URL_PATHS ?=
SAMPLE_COUNT ?= 3
CONCURRENCY ?= 1
TIMEOUT_MS ?= 3000
WARMUP_COUNT ?= 1
KEEP_LAST_N ?= 20
X_REQUEST_ID ?= demo-run-123

# analysis.run / report.export
SNAPSHOT_ID ?=   # set manually OR auto-extract with jq from OUT_JSON

help:
	@echo "Targets:"
	@echo "  make server              # long-running, paste JSON lines manually"
	@echo "  make collect             # collect.run -> saves response to $(OUT_JSON)"
	@echo "  make analyze SNAPSHOT_ID=...   # analysis.run"
	@echo "  make report  SNAPSHOT_ID=...   # report.export"
	@echo "  make show                # print last response + try to extract ids"
	@echo ""
	@echo "Examples:"
	@echo "  make collect SPX_DIR=/tmp/spx BASE_URL=https://zoocomplex.com.ua URL_PATH=/"
	@echo "  make show"
	@echo "  make analyze SNAPSHOT_ID=PUT_ID_HERE TOP_N=5"
	@echo "  make report  SNAPSHOT_ID=PUT_ID_HERE TOP_N=5"
	@echo ""
	@echo "Logs: $(STDERR_LOG)"

server:
	XDEBUG_TRIGGER="$(XDEBUG_TRIGGER)" $(MCP_CMD)

collect:
	@set -euo pipefail; \
	url_paths_json='["$(URL_PATH)"]'; \
	if [[ -n "$(strip $(URL_PATHS))" ]]; then \
	  url_paths_json='['; first=1; \
	  for p in $(URL_PATHS); do \
	    if [[ $$first -eq 0 ]]; then url_paths_json="$$url_paths_json,"; fi; \
	    url_paths_json="$$url_paths_json\"$$p\""; first=0; \
	  done; \
	  url_paths_json="$$url_paths_json]"; \
	fi; \
	printf '%s\n' "{\"jsonrpc\":\"2.0\",\"id\":\"col-1\",\"method\":\"collect.run\",\"params\":{\"correlation_id\":\"$(CORRELATION_ID)\",\"spx_dirs\":[\"$(SPX_DIR)\"],\"slow_log_path\":\"$(SLOW_LOG)\",\"base_url\":\"$(BASE_URL)\",\"url_paths\":$$url_paths_json,\"headers_allowlist\":[\"x-request-id\"],\"headers\":{\"x-request-id\":\"$(X_REQUEST_ID)\"},\"sample_count\":$(SAMPLE_COUNT),\"concurrency\":$(CONCURRENCY),\"timeout_ms\":$(TIMEOUT_MS),\"warmup_count\":$(WARMUP_COUNT),\"redaction_rules\":{\"strip_query_params\":true,\"redact_url_userinfo\":true},\"retention\":{\"keep_last_n\":$(KEEP_LAST_N)}}}" | XDEBUG_TRIGGER="$(XDEBUG_TRIGGER)" $(MCP_CMD) 2> "$(STDERR_LOG)" | tee "$(OUT_JSON)"

show:
	@echo "=== $(OUT_JSON) ==="
	@cat "$(OUT_JSON)" 2>/dev/null || echo "(no last response yet)"

analyze:
	@set -euo pipefail; \
	if [[ -z "$(strip $(SNAPSHOT_ID))" ]]; then \
	  echo "SNAPSHOT_ID is required. Tip: run 'make show' and copy the id." >&2; \
	  exit 2; \
	fi; \
	printf '%s\n' '{"jsonrpc":"2.0","id":"ana-1","method":"analysis.run","params":{"correlation_id":"$(CORRELATION_ID)","normalized_snapshot_id":"$(SNAPSHOT_ID)","top_n":$(TOP_N)}}' | XDEBUG_TRIGGER="$(XDEBUG_TRIGGER)" $(MCP_CMD) 2> "$(STDERR_LOG)" | tee "$(OUT_JSON)"

report:
	@set -euo pipefail; \
	if [[ -z "$(strip $(SNAPSHOT_ID))" ]]; then \
	  echo "SNAPSHOT_ID is required. Tip: run 'make show' and copy the id." >&2; \
	  exit 2; \
	fi; \
	printf '%s\n' '{"jsonrpc":"2.0","id":"rep-1","method":"report.export","params":{"correlation_id":"$(CORRELATION_ID)","normalized_snapshot_id":"$(SNAPSHOT_ID)","top_n":$(TOP_N)}}' | XDEBUG_TRIGGER="$(XDEBUG_TRIGGER)" $(MCP_CMD) 2> "$(STDERR_LOG)" | tee "$(OUT_JSON)"
