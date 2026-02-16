SHELL := /bin/bash
.DEFAULT_GOAL := help

MCP_CMD ?= php bin/console app:mcp:server:run

STDERR_LOG ?= /tmp/mcp.stderr
COLLECT_OUT ?= /tmp/mcp.last.json
INGEST_OUT ?= /tmp/mcp.ingest.json
ANALYSIS_OUT ?= /tmp/mcp.analysis.json
REPORT_OUT ?= /tmp/mcp.report.json
SNAPSHOT_ID_FILE ?= /tmp/mcp.snapshot_id

CORRELATION_ID ?= corr-$(shell date +%Y%m%d_%H%M%S)
TOP_N ?= 5

# collect.run inputs
SPX_DIRS ?= /tmp/spx
SLOW_LOG ?= /opt/homebrew/var/mysql/mysql-slow.log
BASE_URL ?= https://zoocomplex.com.ua
URL_PATHS ?= /
SAMPLE_COUNT ?= 3
CONCURRENCY ?= 1
TIMEOUT_MS ?= 3000
WARMUP_COUNT ?= 1
KEEP_LAST_N ?= 20
X_REQUEST_ID ?= demo-run-123

# artifacts.ingest explicit-mode inputs
SPX_JSON ?=
SPX_TXT_GZ ?=
TIMINGS_JSON ?=

# artifacts.ingest bundle-mode inputs
BUNDLE_DIR ?=
BUNDLES_ROOT ?= var/mcp/bundles

# Optional Xdebug prefix for server process
DEBUG ?= 0
XDEBUG_TRIGGER ?= PHPSTORM
SERVER_PREFIX :=
ifeq ($(DEBUG),1)
SERVER_PREFIX := XDEBUG_TRIGGER=$(XDEBUG_TRIGGER) XDEBUG_MODE=debug
endif

SNAPSHOT_ID ?=

.PHONY: help server collect ingest analyze report show \
	collect.run artifacts.ingest analysis.run report.export

help:
	@echo "Targets:"
	@echo "  make collect                      # collect.run -> $(COLLECT_OUT)"
	@echo "  make ingest                       # artifacts.ingest -> $(INGEST_OUT)"
	@echo "  make analyze [SNAPSHOT_ID=...]    # analysis.run -> $(ANALYSIS_OUT)"
	@echo "  make report  [SNAPSHOT_ID=...]    # report.export -> $(REPORT_OUT)"
	@echo "  make show                         # print current output files"
	@echo ""
	@echo "Method aliases:"
	@echo "  make collect.run"
	@echo "  make artifacts.ingest"
	@echo "  make analysis.run"
	@echo "  make report.export"
	@echo ""
	@echo "Examples:"
	@echo "  make collect BASE_URL=https://example.local SPX_DIRS='/tmp/spx-a /tmp/spx-b' SLOW_LOG=/tmp/mysql-slow.log URL_PATHS='/ /health'"
	@echo "  make ingest BUNDLE_DIR=var/mcp/bundles/bundle_YYYYMMDD_HHMMSS_xxx"
	@echo "  make analyze"
	@echo "  make report"

server:
	@set -euo pipefail; \
	$(SERVER_PREFIX) $(MCP_CMD)

collect:
	@set -euo pipefail; \
	if [[ -z "$(strip $(URL_PATHS))" ]]; then \
		echo "URL_PATHS must be non-empty (space-separated paths)." >&2; \
		exit 2; \
	fi; \
	if [[ -z "$(strip $(SPX_DIRS))" ]]; then \
		echo "SPX_DIRS must be non-empty (space-separated directories)." >&2; \
		exit 2; \
	fi; \
	spx_dirs_json='['; \
	spx_first=1; \
	for p in $(SPX_DIRS); do \
		esc="$$p"; \
		esc="$${esc//\\/\\\\}"; \
		esc="$${esc//\"/\\\"}"; \
		if [[ $$spx_first -eq 0 ]]; then spx_dirs_json="$$spx_dirs_json,"; fi; \
		spx_dirs_json="$$spx_dirs_json\"$$esc\""; \
		spx_first=0; \
	done; \
	spx_dirs_json="$$spx_dirs_json]"; \
	url_paths_json='['; \
	url_first=1; \
	for p in $(URL_PATHS); do \
		esc="$$p"; \
		esc="$${esc//\\/\\\\}"; \
		esc="$${esc//\"/\\\"}"; \
		if [[ $$url_first -eq 0 ]]; then url_paths_json="$$url_paths_json,"; fi; \
		url_paths_json="$$url_paths_json\"$$esc\""; \
		url_first=0; \
	done; \
	url_paths_json="$$url_paths_json]"; \
	slow_log_esc="$(SLOW_LOG)"; \
	slow_log_esc="$${slow_log_esc//\\/\\\\}"; \
	slow_log_esc="$${slow_log_esc//\"/\\\"}"; \
	base_url_esc="$(BASE_URL)"; \
	base_url_esc="$${base_url_esc//\\/\\\\}"; \
	base_url_esc="$${base_url_esc//\"/\\\"}"; \
	x_request_id_esc="$(X_REQUEST_ID)"; \
	x_request_id_esc="$${x_request_id_esc//\\/\\\\}"; \
	x_request_id_esc="$${x_request_id_esc//\"/\\\"}"; \
	payload="{\"jsonrpc\":\"2.0\",\"id\":\"col-1\",\"method\":\"collect.run\",\"params\":{\"correlation_id\":\"$(CORRELATION_ID)\",\"spx_dirs\":$$spx_dirs_json,\"slow_log_path\":\"$$slow_log_esc\",\"base_url\":\"$$base_url_esc\",\"url_paths\":$$url_paths_json,\"headers_allowlist\":[\"x-request-id\"],\"headers\":{\"x-request-id\":\"$$x_request_id_esc\"},\"sample_count\":$(SAMPLE_COUNT),\"concurrency\":$(CONCURRENCY),\"timeout_ms\":$(TIMEOUT_MS),\"warmup_count\":$(WARMUP_COUNT),\"redaction_rules\":{\"strip_query_params\":true,\"redact_url_userinfo\":true},\"retention\":{\"keep_last_n\":$(KEEP_LAST_N)}}}"; \
	printf '%s\n' "$$payload" | $(SERVER_PREFIX) $(MCP_CMD) > "$(COLLECT_OUT)" 2> "$(STDERR_LOG)"; \
	echo "collect response: $(COLLECT_OUT)"; \
	echo "stderr log: $(STDERR_LOG)"

ingest:
	@set -euo pipefail; \
	artifacts_json='['; \
	first=1; \
	append_artifact() { \
		local p="$$1"; \
		if [[ ! -r "$$p" ]]; then \
			echo "Artifact file is not readable: $$p" >&2; \
			exit 2; \
		fi; \
		local esc="$$p"; \
		esc="$${esc//\\/\\\\}"; \
		esc="$${esc//\"/\\\"}"; \
		if [[ $$first -eq 0 ]]; then artifacts_json="$$artifacts_json,"; fi; \
		artifacts_json="$$artifacts_json{\"path\":\"$$esc\"}"; \
		first=0; \
	}; \
	use_explicit=0; \
	if [[ -n "$(strip $(SPX_JSON))$(strip $(SPX_TXT_GZ))$(strip $(TIMINGS_JSON))" ]]; then \
		use_explicit=1; \
	fi; \
	if [[ $$use_explicit -eq 1 ]]; then \
		if [[ -z "$(strip $(SPX_JSON))" || -z "$(strip $(SPX_TXT_GZ))" || -z "$(strip $(SLOW_LOG))" || -z "$(strip $(TIMINGS_JSON))" ]]; then \
			echo "Explicit ingest mode requires SPX_JSON, SPX_TXT_GZ, SLOW_LOG, TIMINGS_JSON." >&2; \
			exit 2; \
		fi; \
		append_artifact "$(SPX_JSON)"; \
		append_artifact "$(SPX_TXT_GZ)"; \
		append_artifact "$(SLOW_LOG)"; \
		append_artifact "$(TIMINGS_JSON)"; \
	else \
		bundle_dir="$(BUNDLE_DIR)"; \
		if [[ -z "$$bundle_dir" ]]; then \
			bundle_dir="$$(ls -1dt $(BUNDLES_ROOT)/bundle_* 2>/dev/null | head -n 1 || true)"; \
		fi; \
		if [[ -z "$$bundle_dir" || ! -d "$$bundle_dir" ]]; then \
			echo "Bundle directory not found. Set BUNDLE_DIR or ensure $(BUNDLES_ROOT)/bundle_* exists." >&2; \
			exit 2; \
		fi; \
		for f in "$$bundle_dir"/raw/spx/*.json; do \
			[[ -e "$$f" ]] || continue; \
			append_artifact "$$f"; \
		done; \
		for f in "$$bundle_dir"/raw/spx/*.txt.gz; do \
			[[ -e "$$f" ]] || continue; \
			append_artifact "$$f"; \
		done; \
		append_artifact "$$bundle_dir/raw/slowlog/mysql-slow.log"; \
		append_artifact "$$bundle_dir/timings.json"; \
	fi; \
	artifacts_json="$$artifacts_json]"; \
	if [[ "$$artifacts_json" == "[]" ]]; then \
		echo "No artifacts resolved for ingest." >&2; \
		exit 2; \
	fi; \
	payload="{\"jsonrpc\":\"2.0\",\"id\":\"ing-1\",\"method\":\"artifacts.ingest\",\"params\":{\"correlation_id\":\"$(CORRELATION_ID)\",\"artifacts\":$$artifacts_json}}"; \
	printf '%s\n' "$$payload" | $(SERVER_PREFIX) $(MCP_CMD) > "$(INGEST_OUT)" 2> "$(STDERR_LOG)"; \
	php -r '$$d=json_decode(file_get_contents("$(INGEST_OUT)"),true); if(!is_array($$d)){fwrite(STDERR,"Invalid ingest response JSON\n"); exit(1);} $$id=$$d["result"]["normalized_snapshot_id"]??""; if(!is_string($$id) || $$id===""){fwrite(STDERR,"normalized_snapshot_id missing in ingest response\n"); exit(1);} file_put_contents("$(SNAPSHOT_ID_FILE)",$$id."\n"); echo $$id,PHP_EOL;'; \
	echo "ingest response: $(INGEST_OUT)"; \
	echo "snapshot id file: $(SNAPSHOT_ID_FILE)"

analyze:
	@set -euo pipefail; \
	snapshot_id="$(strip $(SNAPSHOT_ID))"; \
	if [[ -z "$$snapshot_id" && -r "$(SNAPSHOT_ID_FILE)" ]]; then \
		snapshot_id="$$(tr -d '\r\n' < "$(SNAPSHOT_ID_FILE)")"; \
	fi; \
	if [[ -z "$$snapshot_id" ]]; then \
		echo "SNAPSHOT_ID is required (env var or $(SNAPSHOT_ID_FILE))." >&2; \
		exit 2; \
	fi; \
	payload="{\"jsonrpc\":\"2.0\",\"id\":\"ana-1\",\"method\":\"analysis.run\",\"params\":{\"correlation_id\":\"$(CORRELATION_ID)\",\"normalized_snapshot_id\":\"$$snapshot_id\",\"top_n\":$(TOP_N)}}"; \
	printf '%s\n' "$$payload" | $(SERVER_PREFIX) $(MCP_CMD) > "$(ANALYSIS_OUT)" 2> "$(STDERR_LOG)"; \
	echo "analysis response: $(ANALYSIS_OUT)"

report:
	@set -euo pipefail; \
	snapshot_id="$(strip $(SNAPSHOT_ID))"; \
	if [[ -z "$$snapshot_id" && -r "$(SNAPSHOT_ID_FILE)" ]]; then \
		snapshot_id="$$(tr -d '\r\n' < "$(SNAPSHOT_ID_FILE)")"; \
	fi; \
	if [[ -z "$$snapshot_id" ]]; then \
		echo "SNAPSHOT_ID is required (env var or $(SNAPSHOT_ID_FILE))." >&2; \
		exit 2; \
	fi; \
	payload="{\"jsonrpc\":\"2.0\",\"id\":\"rep-1\",\"method\":\"report.export\",\"params\":{\"correlation_id\":\"$(CORRELATION_ID)\",\"normalized_snapshot_id\":\"$$snapshot_id\",\"top_n\":$(TOP_N)}}"; \
	printf '%s\n' "$$payload" | $(SERVER_PREFIX) $(MCP_CMD) > "$(REPORT_OUT)" 2> "$(STDERR_LOG)"; \
	php -r '$$d=json_decode(file_get_contents("$(REPORT_OUT)"),true); $$m=$$d["result"]["markdown_path"]??""; $$j=$$d["result"]["json_path"]??""; if(is_string($$m) && $$m!==""){echo "markdown_path=$$m\n";} if(is_string($$j) && $$j!==""){echo "json_path=$$j\n";}'; \
	echo "report response: $(REPORT_OUT)"

show:
	@set -euo pipefail; \
	for f in "$(COLLECT_OUT)" "$(INGEST_OUT)" "$(ANALYSIS_OUT)" "$(REPORT_OUT)" "$(SNAPSHOT_ID_FILE)" "$(STDERR_LOG)"; do \
		if [[ -e "$$f" ]]; then \
			echo "=== $$f ==="; \
			cat "$$f"; \
		else \
			echo "=== $$f (missing) ==="; \
		fi; \
	done

collect.run: collect
artifacts.ingest: ingest
analysis.run: analyze
report.export: report
