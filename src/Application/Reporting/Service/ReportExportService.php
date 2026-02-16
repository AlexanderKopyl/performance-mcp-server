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

        $reportPayload = new ReportPayload(
            snapshotId: new SnapshotId($snapshotId),
            executiveSummary: is_array($analysis['summary'] ?? null) ? $analysis['summary'] : [],
            findingsBySeverity: is_array($analysis['findings_by_severity'] ?? null)
                ? $analysis['findings_by_severity']
                : ['P0' => [], 'P1' => [], 'P2' => []],
            evidenceAppendix: $this->buildEvidenceAppendix($analysis),
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
     * @return list<array<string, mixed>>
     */
    private function buildEvidenceAppendix(array $analysis): array
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
}
