<?php

declare(strict_types=1);

namespace App\Application\Analysis\Service;

use App\Application\Artifacts\Contract\SnapshotStoreInterface;
use App\Domain\Analysis\AnalysisThresholds;
use App\Domain\Analysis\SnapshotAnalysisEngine;
use App\Domain\Model\Finding;
use App\Domain\Model\SnapshotId;
use InvalidArgumentException;

final readonly class AnalysisRunService
{
    public function __construct(
        private SnapshotStoreInterface $snapshotStore,
        private SnapshotAnalysisEngine $analysisEngine,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function run(string $snapshotId, array $params = []): ?array
    {
        $snapshot = $this->snapshotStore->load(new SnapshotId($snapshotId));
        if ($snapshot === null) {
            return null;
        }

        $topN = $this->normalizeTopN($params['top_n'] ?? null);
        $thresholdInput = $this->normalizeThresholdInput($params);
        $thresholds = AnalysisThresholds::fromInput($thresholdInput);
        $analyzed = $this->analysisEngine->analyze($snapshot, $thresholds, $topN);

        /** @var list<Finding> $findings */
        $findings = $analyzed['findings'];
        $findingsBySeverity = $this->groupFindingsBySeverity($findings);

        return [
            'normalized_snapshot_id' => $snapshot->id->value,
            'summary' => [
                'endpoint_count' => count($snapshot->requestProfiles),
                'query_count' => count($snapshot->dbQuerySamples),
                'finding_count' => count($findings),
                'p0_count' => count($findingsBySeverity['P0']),
                'p1_count' => count($findingsBySeverity['P1']),
                'p2_count' => count($findingsBySeverity['P2']),
                'top_n' => $topN,
            ],
            'ranking_thresholds' => $thresholds->table(),
            'open_questions' => $thresholds->openQuestions(),
            'aggregates' => $analyzed['aggregates'],
            'findings' => array_map(static fn (Finding $finding): array => $finding->toArray(), $findings),
            'findings_by_severity' => [
                'P0' => array_map(static fn (Finding $finding): array => $finding->toArray(), $findingsBySeverity['P0']),
                'P1' => array_map(static fn (Finding $finding): array => $finding->toArray(), $findingsBySeverity['P1']),
                'P2' => array_map(static fn (Finding $finding): array => $finding->toArray(), $findingsBySeverity['P2']),
            ],
        ];
    }

    private function normalizeTopN(mixed $input): int
    {
        if (!is_int($input)) {
            return 5;
        }

        return max(1, min(20, $input));
    }

    /**
     * @param list<Finding> $findings
     * @return array{P0:list<Finding>,P1:list<Finding>,P2:list<Finding>}
     */
    private function groupFindingsBySeverity(array $findings): array
    {
        $result = ['P0' => [], 'P1' => [], 'P2' => []];
        foreach ($findings as $finding) {
            if (isset($result[$finding->severity])) {
                $result[$finding->severity][] = $finding;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function normalizeThresholdInput(array $params): ?array
    {
        if (!array_key_exists('thresholds', $params)) {
            return null;
        }

        if (!is_array($params['thresholds'])) {
            throw new InvalidArgumentException('params.thresholds must be an object when provided.');
        }

        return $params['thresholds'];
    }
}
