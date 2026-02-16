<?php

declare(strict_types=1);

namespace App\Domain\Model;

final readonly class DbQuerySample
{
    /**
     * @param list<string> $examples
     * @param list<EvidenceRef> $evidence
     */
    public function __construct(
        public string $fingerprint,
        public float $totalTimeMs,
        public float $avgTimeMs,
        public int $count,
        public ?float $lockMs,
        public ?float $rowsExamined,
        public array $examples = [],
        public array $evidence = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'total_time_ms' => $this->totalTimeMs,
            'avg_time_ms' => $this->avgTimeMs,
            'count' => $this->count,
            'lock_ms' => $this->lockMs,
            'rows_examined' => $this->rowsExamined,
            'examples' => $this->examples,
            'evidence' => array_map(static fn (EvidenceRef $ref): array => $ref->toArray(), $this->evidence),
        ];
    }
}
