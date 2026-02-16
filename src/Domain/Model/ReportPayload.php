<?php

declare(strict_types=1);

namespace App\Domain\Model;

final readonly class ReportPayload
{
    /**
     * @param array<string, scalar|null> $executiveSummary
     * @param array{P0:list<array<string, mixed>>,P1:list<array<string, mixed>>,P2:list<array<string, mixed>>} $findingsBySeverity
     * @param array{top_endpoints:list<array<string, mixed>>,top_queries:list<array<string, mixed>>,top_spans:list<array<string, mixed>>} $observations
     * @param list<array<string, mixed>> $evidenceAppendix
     * @param list<string> $openQuestions
     * @param array<string, array{P0:float,P1:float,P2:float,source:string}> $rankingThresholds
     */
    public function __construct(
        public SnapshotId $snapshotId,
        public array $executiveSummary = [],
        public array $findingsBySeverity = ['P0' => [], 'P1' => [], 'P2' => []],
        public array $observations = ['top_endpoints' => [], 'top_queries' => [], 'top_spans' => []],
        public array $evidenceAppendix = [],
        public array $openQuestions = [],
        public array $rankingThresholds = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'snapshot_id' => $this->snapshotId->value,
            'executive_summary' => $this->executiveSummary,
            'findings_by_severity' => [
                'P0' => $this->findingsBySeverity['P0'] ?? [],
                'P1' => $this->findingsBySeverity['P1'] ?? [],
                'P2' => $this->findingsBySeverity['P2'] ?? [],
            ],
            'observations' => [
                'top_endpoints' => $this->observations['top_endpoints'] ?? [],
                'top_queries' => $this->observations['top_queries'] ?? [],
                'top_spans' => $this->observations['top_spans'] ?? [],
            ],
            'evidence_appendix' => $this->evidenceAppendix,
            'open_questions' => $this->openQuestions,
            'ranking_thresholds' => $this->rankingThresholds,
        ];
    }
}
