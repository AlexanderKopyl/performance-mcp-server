<?php

declare(strict_types=1);

namespace App\Application\Reporting\Service;

use App\Application\Analysis\Service\AnalysisRunService;
use App\Application\Reporting\Contract\ReportWriterInterface;
use App\Domain\Model\ReportPayload;
use App\Domain\Model\SnapshotId;
use App\Shared\Util\CanonicalJson;

final readonly class ReportExportService
{
    public function __construct(
        private AnalysisRunService $analysisRunService,
        private ReportWriterInterface $reportWriter,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function export(string $snapshotId, array $params = []): ?array
    {
        $analysis = $this->analysisRunService->run($snapshotId, $params);
        if ($analysis === null) {
            return null;
        }

        $observations = $this->extractObservations($analysis, $snapshotId);
        $reportPayload = new ReportPayload(
            snapshotId: new SnapshotId($snapshotId),
            executiveSummary: is_array($analysis['summary'] ?? null) ? $analysis['summary'] : [],
            findingsBySeverity: is_array($analysis['findings_by_severity'] ?? null)
                ? $analysis['findings_by_severity']
                : ['P0' => [], 'P1' => [], 'P2' => []],
            observations: $observations,
            evidenceAppendix: $this->buildEvidenceAppendix($analysis, $observations),
            openQuestions: is_array($analysis['open_questions'] ?? null)
                ? array_values(array_filter($analysis['open_questions'], static fn (mixed $v): bool => is_string($v)))
                : [],
            rankingThresholds: is_array($analysis['ranking_thresholds'] ?? null) ? $analysis['ranking_thresholds'] : [],
        );

        $jsonPayload = $reportPayload->toArray();
        $markdown = $this->renderMarkdown($reportPayload);
        $reportId = substr(hash('sha256', CanonicalJson::encode([
            'snapshot_id' => $snapshotId,
            'report' => $jsonPayload,
        ])), 0, 16);
        $written = $this->reportWriter->write($reportId, $markdown, $jsonPayload);

        return [
            'normalized_snapshot_id' => $snapshotId,
            'report_id' => $reportId,
            'markdown_path' => $written['markdown_path'],
            'json_path' => $written['json_path'],
            'markdown' => $markdown,
            'report' => $jsonPayload,
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array{top_endpoints:list<array<string, mixed>>,top_queries:list<array<string, mixed>>,top_spans:list<array<string, mixed>>} $observations
     * @return list<array<string, mixed>>
     */
    private function buildEvidenceAppendix(array $analysis, array $observations): array
    {
        $findings = is_array($analysis['findings'] ?? null) ? $analysis['findings'] : [];
        $appendix = [];
        $seen = [];

        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }

            $this->collectEvidenceRows($finding['evidence'] ?? [], $appendix, $seen);

            $recommendations = is_array($finding['recommendations'] ?? null) ? $finding['recommendations'] : [];
            foreach ($recommendations as $recommendation) {
                if (!is_array($recommendation)) {
                    continue;
                }

                $this->collectEvidenceRows($recommendation['evidence'] ?? [], $appendix, $seen);
            }
        }

        foreach (['top_endpoints', 'top_queries', 'top_spans'] as $group) {
            $rows = is_array($observations[$group] ?? null) ? $observations[$group] : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $this->collectEvidenceRows($row['evidence'] ?? [], $appendix, $seen);
            }
        }

        return $appendix;
    }

    /**
     * @param mixed $rows
     * @param list<array<string, mixed>> $appendix
     * @param array<string, bool> $seen
     */
    private function collectEvidenceRows(mixed $rows, array &$appendix, array &$seen): void
    {
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $key = CanonicalJson::encode($row);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $appendix[] = $row;
        }
    }

    private function renderMarkdown(ReportPayload $payload): string
    {
        $lines = [];

        $lines[] = '# Executive Summary';
        $lines[] = sprintf('- Snapshot ID: `%s`', $payload->snapshotId->value);
        foreach ($payload->executiveSummary as $key => $value) {
            $lines[] = sprintf('- %s: `%s`', $key, (string) $value);
        }
        $lines[] = '';

        $lines[] = '# Thresholds Used';
        if ($payload->rankingThresholds === []) {
            $lines[] = '- None';
        } else {
            foreach ($payload->rankingThresholds as $metric => $band) {
                if (!is_array($band)) {
                    continue;
                }
                $lines[] = sprintf(
                    '- `%s`: P0=`%s`ms, P1=`%s`ms, P2=`%s`ms (source=`%s`)',
                    (string) $metric,
                    (string) ($band['P0'] ?? 'n/a'),
                    (string) ($band['P1'] ?? 'n/a'),
                    (string) ($band['P2'] ?? 'n/a'),
                    (string) ($band['source'] ?? 'unknown'),
                );
            }
        }
        $lines[] = '';

        $lines[] = '# Observations';
        $lines[] = '## Endpoints';
        $endpointRows = is_array($payload->observations['top_endpoints'] ?? null) ? $payload->observations['top_endpoints'] : [];
        if ($endpointRows === []) {
            $lines[] = '- None';
        } else {
            foreach ($endpointRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $lines[] = sprintf(
                    '- `%s` sample_count=`%d`, wall_ms(avg/max)=`%s/%s`, ttfb_ms(avg/max)=`%s/%s`',
                    (string) ($row['endpoint'] ?? 'n/a'),
                    (int) ($row['sample_count'] ?? 0),
                    (string) ($row['wall_ms_avg'] ?? 'n/a'),
                    (string) ($row['wall_ms_max'] ?? 'n/a'),
                    (string) ($row['ttfb_ms_avg'] ?? 'n/a'),
                    (string) ($row['ttfb_ms_max'] ?? 'n/a'),
                );
                $lines[] = sprintf(
                    '- Percentiles: p50/p95 unavailable in normalized input (reported metrics above use avg/max).'
                );
                $lines[] = sprintf('- Evidence: %s', implode('; ', $this->evidencePointers($row['evidence'] ?? [])));
            }
        }
        $lines[] = '';

        $lines[] = '## Queries';
        $queryRows = is_array($payload->observations['top_queries'] ?? null) ? $payload->observations['top_queries'] : [];
        if ($queryRows === []) {
            $lines[] = '- None';
        } else {
            foreach ($queryRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $lines[] = sprintf(
                    '- `%s` total_time_ms=`%s`, count=`%d`, avg_time_ms=`%s`',
                    (string) ($row['fingerprint'] ?? 'n/a'),
                    (string) ($row['query_total_time_ms'] ?? 'n/a'),
                    (int) ($row['count'] ?? 0),
                    (string) ($row['avg_time_ms'] ?? 'n/a'),
                );
                $lines[] = sprintf('- Evidence: %s', implode('; ', $this->evidencePointers($row['evidence'] ?? [])));
            }
        }
        $lines[] = '';

        $lines[] = '## SPX Spans';
        $spanRows = is_array($payload->observations['top_spans'] ?? null) ? $payload->observations['top_spans'] : [];
        if ($spanRows === []) {
            $lines[] = '- None';
        } else {
            foreach ($spanRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $lines[] = sprintf(
                    '- `%s` (%s) endpoint=`%s`, self_ms=`%s`, total_ms=`%s`',
                    (string) ($row['span_label'] ?? 'n/a'),
                    (string) ($row['span_type'] ?? 'n/a'),
                    (string) ($row['endpoint'] ?? 'n/a'),
                    (string) ($row['self_ms'] ?? 'n/a'),
                    (string) ($row['total_ms'] ?? 'n/a'),
                );
                $spxProfiles = is_array($row['spx_profiles'] ?? null) ? $row['spx_profiles'] : [];
                $lines[] = sprintf(
                    '- SPX profiles: %s',
                    $spxProfiles === [] ? 'n/a' : implode(', ', $spxProfiles),
                );
                $lines[] = sprintf('- Evidence: %s', implode('; ', $this->evidencePointers($row['evidence'] ?? [])));
            }
        }
        $lines[] = '';

        $lines[] = '# Findings';
        foreach (['P0', 'P1', 'P2'] as $severity) {
            $lines[] = sprintf('## %s', $severity);
            $findings = is_array($payload->findingsBySeverity[$severity] ?? null) ? $payload->findingsBySeverity[$severity] : [];
            if ($findings === []) {
                $lines[] = '- None';
                $lines[] = '';
                continue;
            }

            foreach ($findings as $finding) {
                if (!is_array($finding)) {
                    continue;
                }

                $lines[] = sprintf('### %s', (string) ($finding['title'] ?? 'Untitled finding'));
                $lines[] = sprintf('- Finding ID: `%s`', (string) ($finding['id'] ?? 'n/a'));
                $lines[] = sprintf('- Impact: %s', (string) ($finding['impact_summary'] ?? 'n/a'));

                $metrics = is_array($finding['metrics'] ?? null) ? $finding['metrics'] : [];
                if ($metrics !== []) {
                    $metricParts = [];
                    foreach ($metrics as $key => $value) {
                        $metricParts[] = sprintf('%s=%s', (string) $key, $value === null ? 'null' : (string) $value);
                    }
                    $lines[] = sprintf('- Metrics: `%s`', implode(', ', $metricParts));
                }

                $recommendations = is_array($finding['recommendations'] ?? null) ? $finding['recommendations'] : [];
                if ($recommendations !== []) {
                    $lines[] = '- Recommendations:';
                    foreach ($recommendations as $recommendation) {
                        if (!is_array($recommendation)) {
                            continue;
                        }
                        $lines[] = sprintf('  - %s', (string) ($recommendation['action'] ?? 'n/a'));
                        $lines[] = sprintf('  - Verify: %s', (string) ($recommendation['verification_step'] ?? 'n/a'));
                    }
                }

                $lines[] = '';
            }
        }

        $lines[] = '# Evidence Appendix';
        if ($payload->evidenceAppendix === []) {
            $lines[] = '- None';
        } else {
            foreach ($payload->evidenceAppendix as $index => $evidence) {
                $source = (string) ($evidence['source'] ?? 'unknown_source');
                $file = (string) ($evidence['file'] ?? 'unknown_file');
                $recordId = (string) ($evidence['record_id'] ?? 'n/a');
                $lineRange = $evidence['line_range'] ?? null;
                $lineText = 'n/a';
                if (is_array($lineRange) && isset($lineRange['start'], $lineRange['end'])) {
                    $lineText = sprintf('%d-%d', (int) $lineRange['start'], (int) $lineRange['end']);
                }

                $lines[] = sprintf(
                    '- E%d: source=`%s`, file=`%s`, record_id=`%s`, lines=`%s`',
                    $index + 1,
                    $source,
                    $file,
                    $recordId,
                    $lineText,
                );
            }
        }
        $lines[] = '';

        $lines[] = '# Open Questions';
        if ($payload->openQuestions === []) {
            $lines[] = '- None';
        } else {
            foreach ($payload->openQuestions as $question) {
                $lines[] = sprintf('- %s', $question);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array{top_endpoints:list<array<string, mixed>>,top_queries:list<array<string, mixed>>,top_spans:list<array<string, mixed>>}
     */
    private function extractObservations(array $analysis, string $snapshotId): array
    {
        $aggregates = is_array($analysis['aggregates'] ?? null) ? $analysis['aggregates'] : [];

        return [
            'top_endpoints' => $this->extractEndpointObservations($aggregates['top_endpoints'] ?? [], $snapshotId),
            'top_queries' => $this->extractQueryObservations($aggregates['top_queries'] ?? [], $snapshotId),
            'top_spans' => $this->extractSpanObservations($aggregates['top_spans'] ?? [], $snapshotId),
        ];
    }

    /**
     * @param mixed $rows
     * @return list<array<string, mixed>>
     */
    private function extractEndpointObservations(mixed $rows, string $snapshotId): array
    {
        if (!is_array($rows)) {
            return [];
        }

        /** @var array<string, array{endpoint:string,sample_count:int,wall_total:float,wall_ms_max:float,ttfb_total:float,ttfb_count:int,ttfb_ms_max:float,evidence:list<array<string, mixed>>}> $byEndpoint */
        $byEndpoint = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['endpoint'] ?? null)) {
                continue;
            }

            $endpoint = $row['endpoint'];
            if (!isset($byEndpoint[$endpoint])) {
                $byEndpoint[$endpoint] = [
                    'endpoint' => $endpoint,
                    'sample_count' => 0,
                    'wall_total' => 0.0,
                    'wall_ms_max' => 0.0,
                    'ttfb_total' => 0.0,
                    'ttfb_count' => 0,
                    'ttfb_ms_max' => 0.0,
                    'evidence' => [],
                ];
            }

            $wall = is_numeric($row['wall_ms'] ?? null) ? (float) $row['wall_ms'] : 0.0;
            $ttfb = is_numeric($row['ttfb_ms'] ?? null) ? (float) $row['ttfb_ms'] : null;

            $byEndpoint[$endpoint]['sample_count']++;
            $byEndpoint[$endpoint]['wall_total'] += $wall;
            $byEndpoint[$endpoint]['wall_ms_max'] = max($byEndpoint[$endpoint]['wall_ms_max'], $wall);
            if ($ttfb !== null) {
                $byEndpoint[$endpoint]['ttfb_total'] += $ttfb;
                $byEndpoint[$endpoint]['ttfb_count']++;
                $byEndpoint[$endpoint]['ttfb_ms_max'] = max($byEndpoint[$endpoint]['ttfb_ms_max'], $ttfb);
            }

            $this->mergeEvidence($byEndpoint[$endpoint]['evidence'], $row['evidence'] ?? []);
        }

        $result = [];
        foreach ($byEndpoint as $entry) {
            $ttfbAvg = $entry['ttfb_count'] > 0 ? round($entry['ttfb_total'] / $entry['ttfb_count'], 3) : null;
            $ttfbMax = $entry['ttfb_count'] > 0 ? round($entry['ttfb_ms_max'], 3) : null;

            $result[] = [
                'snapshot_id' => $snapshotId,
                'endpoint' => $entry['endpoint'],
                'sample_count' => $entry['sample_count'],
                'wall_ms_avg' => round($entry['wall_total'] / max(1, $entry['sample_count']), 3),
                'wall_ms_max' => round($entry['wall_ms_max'], 3),
                'ttfb_ms_avg' => $ttfbAvg,
                'ttfb_ms_max' => $ttfbMax,
                'evidence' => array_slice($entry['evidence'], 0, 3),
            ];
        }

        usort($result, static function (array $a, array $b): int {
            $sort = ((float) ($b['wall_ms_max'] ?? 0.0)) <=> ((float) ($a['wall_ms_max'] ?? 0.0));
            if ($sort !== 0) {
                return $sort;
            }

            return strcmp((string) ($a['endpoint'] ?? ''), (string) ($b['endpoint'] ?? ''));
        });

        return $result;
    }

    /**
     * @param mixed $rows
     * @return list<array<string, mixed>>
     */
    private function extractQueryObservations(mixed $rows, string $snapshotId): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['fingerprint'] ?? null)) {
                continue;
            }

            $result[] = [
                'snapshot_id' => $snapshotId,
                'fingerprint' => $row['fingerprint'],
                'query_total_time_ms' => is_numeric($row['query_total_time_ms'] ?? null) ? round((float) $row['query_total_time_ms'], 3) : null,
                'avg_time_ms' => is_numeric($row['avg_time_ms'] ?? null) ? round((float) $row['avg_time_ms'], 3) : null,
                'count' => is_int($row['count'] ?? null) ? $row['count'] : 0,
                'evidence' => $this->normalizeEvidence($row['evidence'] ?? []),
            ];
        }

        return $result;
    }

    /**
     * @param mixed $rows
     * @return list<array<string, mixed>>
     */
    private function extractSpanObservations(mixed $rows, string $snapshotId): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $evidence = $this->normalizeEvidence($row['evidence'] ?? []);
            $spxProfiles = [];
            foreach ($evidence as $evidenceRef) {
                $file = is_string($evidenceRef['file'] ?? null) ? $evidenceRef['file'] : null;
                if ($file === null) {
                    continue;
                }
                $spxProfiles[$file] = true;
            }

            $result[] = [
                'snapshot_id' => $snapshotId,
                'endpoint' => is_string($row['endpoint'] ?? null) ? $row['endpoint'] : 'n/a',
                'span_label' => is_string($row['span_label'] ?? null) ? $row['span_label'] : 'n/a',
                'span_type' => is_string($row['span_type'] ?? null) ? $row['span_type'] : 'n/a',
                'self_ms' => is_numeric($row['self_ms'] ?? null) ? round((float) $row['self_ms'], 3) : null,
                'total_ms' => is_numeric($row['total_ms'] ?? null) ? round((float) $row['total_ms'], 3) : null,
                'spx_profiles' => array_keys($spxProfiles),
                'evidence' => $evidence,
            ];
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $target
     * @param mixed $candidate
     */
    private function mergeEvidence(array &$target, mixed $candidate): void
    {
        $existing = [];
        foreach ($target as $row) {
            $existing[CanonicalJson::encode($row)] = true;
        }

        foreach ($this->normalizeEvidence($candidate) as $row) {
            $key = CanonicalJson::encode($row);
            if (isset($existing[$key])) {
                continue;
            }
            $existing[$key] = true;
            $target[] = $row;
        }
    }

    /**
     * @param mixed $candidate
     * @return list<array<string, mixed>>
     */
    private function normalizeEvidence(mixed $candidate): array
    {
        if (!is_array($candidate)) {
            return [];
        }

        $result = [];
        foreach ($candidate as $row) {
            if (is_array($row)) {
                $result[] = $row;
            }
        }

        return array_slice($result, 0, 3);
    }

    /**
     * @param mixed $evidence
     * @return list<string>
     */
    private function evidencePointers(mixed $evidence): array
    {
        $rows = $this->normalizeEvidence($evidence);
        if ($rows === []) {
            return ['none'];
        }

        $pointers = [];
        foreach ($rows as $row) {
            $file = is_string($row['file'] ?? null) ? $row['file'] : 'unknown_file';
            $recordId = is_string($row['record_id'] ?? null) ? $row['record_id'] : 'n/a';
            $source = is_string($row['source'] ?? null) ? $row['source'] : 'unknown_source';
            $pointers[] = sprintf('source=%s file=%s record_id=%s', $source, $file, $recordId);
        }

        return $pointers;
    }
}
