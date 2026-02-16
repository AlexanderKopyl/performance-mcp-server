<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

use App\Domain\Model\DbQuerySample;
use App\Domain\Model\EvidenceRef;
use App\Domain\Model\Finding;
use App\Domain\Model\Recommendation;
use App\Domain\Model\RequestProfile;
use App\Domain\Model\Snapshot;
use App\Domain\Model\Span;

final class SnapshotAnalysisEngine
{
    private const SEVERITY_ORDER = ['P0' => 0, 'P1' => 1, 'P2' => 2];

    /**
     * @return array{findings:list<Finding>,aggregates:array<string, mixed>}
     */
    public function analyze(Snapshot $snapshot, AnalysisThresholds $thresholds, int $topN): array
    {
        $endpointFindings = $this->analyzeEndpoints($snapshot->requestProfiles, $snapshot->dbQuerySamples, $thresholds, $topN);
        $spanFindings = $this->analyzeSpans($snapshot->requestProfiles, $thresholds, $topN);
        $queryFindings = $this->analyzeQueries($snapshot->dbQuerySamples, $thresholds, $topN);

        $findings = array_merge($endpointFindings['findings'], $spanFindings['findings'], $queryFindings['findings']);
        usort($findings, static function (Finding $a, Finding $b): int {
            $severitySort = self::SEVERITY_ORDER[$a->severity] <=> self::SEVERITY_ORDER[$b->severity];
            if ($severitySort !== 0) {
                return $severitySort;
            }

            return strcmp($a->id, $b->id);
        });

        return [
            'findings' => $findings,
            'aggregates' => [
                'top_endpoints' => $endpointFindings['aggregates'],
                'top_spans' => $spanFindings['aggregates'],
                'top_queries' => $queryFindings['aggregates'],
            ],
        ];
    }

    /**
     * @param list<RequestProfile> $profiles
     * @param list<DbQuerySample> $queries
     * @return array{findings:list<Finding>,aggregates:list<array<string, mixed>>}
     */
    private function analyzeEndpoints(array $profiles, array $queries, AnalysisThresholds $thresholds, int $topN): array
    {
        usort($profiles, static function (RequestProfile $a, RequestProfile $b): int {
            $scoreA = max($a->wallMs, $a->ttfbMs ?? 0.0);
            $scoreB = max($b->wallMs, $b->ttfbMs ?? 0.0);
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }

            return strcmp($a->endpoint, $b->endpoint);
        });

        $findings = [];
        $aggregates = [];

        foreach (array_slice($profiles, 0, $topN) as $profile) {
            $wallSeverity = $thresholds->severityFor('endpoint_wall_ms', $profile->wallMs);
            $ttfbSeverity = $profile->ttfbMs !== null
                ? $thresholds->severityFor('endpoint_ttfb_ms', $profile->ttfbMs)
                : null;
            $severity = $this->maxSeverity($wallSeverity, $ttfbSeverity);

            $aggregates[] = [
                'endpoint' => $profile->endpoint,
                'wall_ms' => round($profile->wallMs, 3),
                'ttfb_ms' => $profile->ttfbMs !== null ? round($profile->ttfbMs, 3) : null,
                'severity' => $severity,
                'evidence' => array_map(static fn (EvidenceRef $ref): array => $ref->toArray(), $profile->evidence),
            ];

            if ($severity === null) {
                continue;
            }

            $score = max($profile->wallMs, $profile->ttfbMs ?? 0.0);
            $evidence = $this->limitEvidence($profile->evidence);
            $recommendations = [
                new Recommendation(
                    id: 'endpoint-breakdown',
                    action: sprintf(
                        'Collect endpoint-level breakdown for "%s" by comparing wall vs CPU vs memory in the same capture window.',
                        $profile->endpoint,
                    ),
                    verificationStep: 'Re-run SPX/timing capture and confirm whether wall_ms remains dominant against cpu_ms.',
                    evidence: $evidence,
                ),
                new Recommendation(
                    id: 'endpoint-regression-check',
                    action: sprintf(
                        'Run a controlled baseline request set for "%s" and compare p95 wall/ttfb to this snapshot.',
                        $profile->endpoint,
                    ),
                    verificationStep: 'Use identical traffic volume and validate that p95/p99 latency trend matches this finding.',
                    evidence: $evidence,
                ),
            ];

            $association = $this->findEndpointQueryAssociation($profile, $queries);
            if ($association !== null) {
                $recommendations[] = new Recommendation(
                    id: 'endpoint-query-association',
                    action: sprintf(
                        'Inspect query fingerprint "%s" in the context of endpoint "%s" before attempting mitigations.',
                        $association['fingerprint'],
                        $profile->endpoint,
                    ),
                    verificationStep: 'Trace query call sequence for this endpoint and verify contribution with SQL profiling or EXPLAIN ANALYZE.',
                    evidence: $association['evidence'],
                );
            }

            $findings[] = new Finding(
                id: sprintf('endpoint:%s', hash('sha1', $profile->endpoint)),
                title: sprintf('Slow endpoint %s', $profile->endpoint),
                severity: $severity,
                impactSummary: sprintf(
                    'Endpoint "%s" reached %.3fms wall time%s.',
                    $profile->endpoint,
                    round($profile->wallMs, 3),
                    $profile->ttfbMs !== null ? sprintf(' with %.3fms TTFB', round($profile->ttfbMs, 3)) : '',
                ),
                evidence: $evidence,
                metrics: [
                    'wall_ms' => round($profile->wallMs, 3),
                    'ttfb_ms' => $profile->ttfbMs !== null ? round($profile->ttfbMs, 3) : null,
                    'cpu_ms' => $profile->cpuMs !== null ? round($profile->cpuMs, 3) : null,
                    'mem_mb' => $profile->memMb !== null ? round($profile->memMb, 3) : null,
                    'severity_score_ms' => round($score, 3),
                ],
                recommendations: array_slice($recommendations, 0, 3),
                aggregationProvenance: [
                    'wall_ms' => 'directly from request_profile.wall_ms',
                    'ttfb_ms' => 'directly from request_profile.ttfb_ms when present',
                    'severity_score_ms' => 'max(wall_ms, ttfb_ms)',
                ],
            );
        }

        return ['findings' => $findings, 'aggregates' => $aggregates];
    }

    /**
     * @param list<RequestProfile> $profiles
     * @return array{findings:list<Finding>,aggregates:list<array<string, mixed>>}
     */
    private function analyzeSpans(array $profiles, AnalysisThresholds $thresholds, int $topN): array
    {
        /** @var list<array{endpoint:string,span:Span,score:float}> $rows */
        $rows = [];
        foreach ($profiles as $profile) {
            foreach ($profile->spans as $span) {
                $rows[] = [
                    'endpoint' => $profile->endpoint,
                    'span' => $span,
                    'score' => max($span->selfMs, $span->totalMs),
                ];
            }
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            $labelCompare = strcmp($a['span']->label, $b['span']->label);
            if ($labelCompare !== 0) {
                return $labelCompare;
            }

            return strcmp($a['endpoint'], $b['endpoint']);
        });

        $findings = [];
        $aggregates = [];
        foreach (array_slice($rows, 0, $topN) as $row) {
            $span = $row['span'];
            $severity = $this->maxSeverity(
                $thresholds->severityFor('span_self_ms', $span->selfMs),
                $thresholds->severityFor('span_total_ms', $span->totalMs),
            );

            $aggregates[] = [
                'endpoint' => $row['endpoint'],
                'span_label' => $span->label,
                'span_type' => $span->type,
                'self_ms' => round($span->selfMs, 3),
                'total_ms' => round($span->totalMs, 3),
                'severity' => $severity,
                'evidence' => array_map(static fn (EvidenceRef $ref): array => $ref->toArray(), $span->evidence),
            ];

            if ($severity === null) {
                continue;
            }

            $evidence = $this->limitEvidence($span->evidence);
            $findings[] = new Finding(
                id: sprintf('span:%s', hash('sha1', $row['endpoint'].'|'.$span->type.'|'.$span->label)),
                title: sprintf('Heavy span %s (%s)', $span->label, $row['endpoint']),
                severity: $severity,
                impactSummary: sprintf(
                    'Span "%s" in endpoint "%s" consumed %.3fms self and %.3fms total time.',
                    $span->label,
                    $row['endpoint'],
                    round($span->selfMs, 3),
                    round($span->totalMs, 3),
                ),
                evidence: $evidence,
                metrics: [
                    'self_ms' => round($span->selfMs, 3),
                    'total_ms' => round($span->totalMs, 3),
                    'severity_score_ms' => round(max($span->selfMs, $span->totalMs), 3),
                ],
                recommendations: [
                    new Recommendation(
                        id: 'span-flamegraph',
                        action: sprintf('Profile span "%s" call tree and identify dominant child frames.', $span->label),
                        verificationStep: 'Capture a focused trace and confirm the same span remains in top self_ms contributors.',
                        evidence: $evidence,
                    ),
                    new Recommendation(
                        id: 'span-input-shape',
                        action: sprintf(
                            'Compare input sizes and branching paths that trigger span "%s" in endpoint "%s".',
                            $span->label,
                            $row['endpoint'],
                        ),
                        verificationStep: 'Replay representative requests and confirm whether span duration scales with input shape.',
                        evidence: $evidence,
                    ),
                ],
                aggregationProvenance: [
                    'self_ms' => 'directly from span.self_ms',
                    'total_ms' => 'directly from span.total_ms',
                    'severity_score_ms' => 'max(self_ms, total_ms)',
                ],
            );
        }

        return ['findings' => $findings, 'aggregates' => $aggregates];
    }

    /**
     * @param list<DbQuerySample> $queries
     * @return array{findings:list<Finding>,aggregates:list<array<string, mixed>>}
     */
    private function analyzeQueries(array $queries, AnalysisThresholds $thresholds, int $topN): array
    {
        usort($queries, static function (DbQuerySample $a, DbQuerySample $b): int {
            $scoreA = $a->avgTimeMs * $a->count;
            $scoreB = $b->avgTimeMs * $b->count;
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }

            return strcmp($a->fingerprint, $b->fingerprint);
        });

        $findings = [];
        $aggregates = [];
        foreach (array_slice($queries, 0, $topN) as $query) {
            $contribution = round($query->avgTimeMs * $query->count, 3);
            $severity = $thresholds->severityFor('query_total_time_ms', $contribution);

            $aggregates[] = [
                'fingerprint' => $query->fingerprint,
                'query_total_time_ms' => $contribution,
                'avg_time_ms' => round($query->avgTimeMs, 3),
                'count' => $query->count,
                'severity' => $severity,
                'evidence' => array_map(static fn (EvidenceRef $ref): array => $ref->toArray(), $query->evidence),
            ];

            if ($severity === null) {
                continue;
            }

            $evidence = $this->limitEvidence($query->evidence);
            $findings[] = new Finding(
                id: sprintf('query:%s', $query->fingerprint),
                title: sprintf('Slow query fingerprint %s', substr($query->fingerprint, 0, 12)),
                severity: $severity,
                impactSummary: sprintf(
                    'Fingerprint %s contributes %.3fms total estimated time (avg %.3fms x %d).',
                    $query->fingerprint,
                    $contribution,
                    round($query->avgTimeMs, 3),
                    $query->count,
                ),
                evidence: $evidence,
                metrics: [
                    'query_total_time_ms' => $contribution,
                    'avg_time_ms' => round($query->avgTimeMs, 3),
                    'count' => $query->count,
                    'reported_total_time_ms' => round($query->totalTimeMs, 3),
                    'lock_ms' => $query->lockMs !== null ? round($query->lockMs, 3) : null,
                    'rows_examined' => $query->rowsExamined !== null ? round($query->rowsExamined, 3) : null,
                ],
                recommendations: [
                    new Recommendation(
                        id: 'query-plan',
                        action: sprintf('Run EXPLAIN ANALYZE for fingerprint %s using representative literals.', $query->fingerprint),
                        verificationStep: 'Confirm scan type, row estimates, and execution time align with slow-log evidence.',
                        evidence: $evidence,
                    ),
                    new Recommendation(
                        id: 'query-index-candidate',
                        action: 'Inspect access path and index coverage for filter/join predicates in this fingerprint.',
                        verificationStep: 'Measure avg_time_ms before/after index or rewrite in a controlled staging replay.',
                        evidence: $evidence,
                    ),
                    new Recommendation(
                        id: 'query-volume-check',
                        action: 'Validate whether call frequency can be reduced through caching, batching, or deduplication.',
                        verificationStep: 'Track count and total contribution across a second capture window after the change.',
                        evidence: $evidence,
                    ),
                ],
                aggregationProvenance: [
                    'query_total_time_ms' => 'avg_time_ms * count',
                    'reported_total_time_ms' => 'directly from db_query_sample.total_time_ms',
                ],
            );
        }

        return ['findings' => $findings, 'aggregates' => $aggregates];
    }

    /**
     * @param list<EvidenceRef> $evidence
     * @return list<EvidenceRef>
     */
    private function limitEvidence(array $evidence, int $limit = 3): array
    {
        return array_slice($evidence, 0, $limit);
    }

    private function maxSeverity(?string ...$levels): ?string
    {
        $result = null;
        foreach ($levels as $level) {
            if ($level === null) {
                continue;
            }

            if ($result === null || self::SEVERITY_ORDER[$level] < self::SEVERITY_ORDER[$result]) {
                $result = $level;
            }
        }

        return $result;
    }

    /**
     * @param list<DbQuerySample> $queries
     * @return array{fingerprint:string,evidence:list<EvidenceRef>}|null
     */
    private function findEndpointQueryAssociation(RequestProfile $profile, array $queries): ?array
    {
        $endpointFiles = [];
        foreach ($profile->evidence as $evidence) {
            $endpointFiles[$evidence->file] = true;
        }

        foreach ($queries as $query) {
            $matchingEvidence = [];
            foreach ($query->evidence as $evidence) {
                if (isset($endpointFiles[$evidence->file])) {
                    $matchingEvidence[] = $evidence;
                }
            }

            if ($matchingEvidence !== []) {
                return [
                    'fingerprint' => $query->fingerprint,
                    'evidence' => $this->limitEvidence(array_merge($profile->evidence, $matchingEvidence)),
                ];
            }
        }

        return null;
    }
}
