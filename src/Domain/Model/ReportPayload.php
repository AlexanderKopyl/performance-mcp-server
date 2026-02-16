<?php

declare(strict_types=1);

namespace App\Domain\Model;

final readonly class ReportPayload
{
    /**
     * @param array<string, scalar|null> $executiveSummary
     * @param array{P0:list<array<string, mixed>>,P1:list<array<string, mixed>>,P2:list<array<string, mixed>>} $findingsBySeverity
     * @param list<array<string, mixed>> $evidenceAppendix
     * @param list<string> $openQuestions
     * @param array<string, array{p0:float,p1:float,p2:float,source:string}> $rankingThresholds
     */
    public function __construct(
        public SnapshotId $snapshotId,
        public array $executiveSummary = [],
        public array $findingsBySeverity = ['P0' => [], 'P1' => [], 'P2' => []],
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
            'evidence_appendix' => $this->evidenceAppendix,
            'open_questions' => $this->openQuestions,
            'ranking_thresholds' => $this->rankingThresholds,
        ];
    }
}
