<?php

declare(strict_types=1);

namespace App\Domain\Model;

final readonly class Finding
{
    /**
     * @param list<EvidenceRef> $evidence
     * @param array<string, scalar|null> $metrics
     * @param list<Recommendation> $recommendations
     * @param array<string, string> $aggregationProvenance
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $severity,
        public string $impactSummary,
        public array $evidence = [],
        public array $metrics = [],
        public array $recommendations = [],
        public array $aggregationProvenance = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'severity' => $this->severity,
            'impact_summary' => $this->impactSummary,
            'metrics' => $this->metrics,
            'aggregation_provenance' => $this->aggregationProvenance,
            'evidence' => array_map(static fn (EvidenceRef $ref): array => $ref->toArray(), $this->evidence),
            'recommendations' => array_map(static fn (Recommendation $recommendation): array => $recommendation->toArray(), $this->recommendations),
        ];
    }
}
