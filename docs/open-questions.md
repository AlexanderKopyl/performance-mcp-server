# Open Questions

## 1) Database strategy for future indexing/search
- Option A: PostgreSQL (JSONB + relational metadata).
  - Trade-off: Strong query flexibility and ecosystem, but operational overhead.
- Option B: SQLite for single-host mode.
  - Trade-off: Minimal ops footprint, but weaker concurrency and scaling.
- Option C: ClickHouse for large analytic workloads.
  - Trade-off: Excellent analytical performance, but increased complexity.

## 2) Artifact formats variability and schema evolution
- Option A: Strict versioned schemas per artifact type.
  - Trade-off: High reliability, but slower onboarding of new formats.
- Option B: Tolerant parser adapters with capability flags.
  - Trade-off: Faster integration, but more runtime branching and testing.
- Option C: Hybrid (strict core fields + extensible metadata).
  - Trade-off: Balanced flexibility, requires clear compatibility policy.

## 3) Retention policy controls
- Option A: Keep last N snapshots globally.
  - Trade-off: Simple to reason about, may evict critical historical runs.
- Option B: Time-window retention per artifact type.
  - Trade-off: Better domain control, more config complexity.
- Option C: Size-budget with priority tiers.
  - Trade-off: Efficient disk use, but harder policy predictability.

## 4) Analysis severity threshold policy (P0/P1/P2)
- Option A: Mandatory explicit thresholds per environment.
  - Trade-off: Highest control and explainability, but onboarding friction for first-time users.
- Option B: Conservative defaults with OPEN_QUESTION markers when config missing.
  - Trade-off: Immediate usability and deterministic output, but risk of under-prioritizing real incidents.
- Option C: Hybrid defaults + profile presets (api-heavy, db-heavy, batch-heavy).
  - Trade-off: Better relevance without full manual tuning, but preset drift requires governance.

## 5) SPX artifact variants (currently supported)
- `spx-full-<stamp>-<host>-<pid>-<runid>.json`
  - Parser: `spx-json-v2` (schema inspection, no hardcoded SPX schema dependency).
- `spx-full-<stamp>-<host>-<pid>-<runid>.txt.gz`
  - Parser: `spx-text-gz-v1` (streaming decompression, section marker and timing-line extraction).
- Pairing behavior:
  - Pair key: full shared prefix before extension.
  - Status values: `paired` or `partial`.
  - Partial ingestion is allowed; missing counterpart is reported in validation metadata.

## 6) SPX unknown schema mapping policy
- Option A: Keep strict metric mapping to explicit `*_ms` keys only.
  - Trade-off: Lower false positives, but can miss useful fields in alternate dumps.
- Option B: Add per-schema adapters once real-world variants are captured.
  - Trade-off: Better extraction coverage, but more maintenance and test matrix growth.
- Option C: Add an explicit `unknown_variant` marker when spans/metrics cannot be extracted.
  - Trade-off: Clear operator signal, but noisier validation output until adapters exist.
