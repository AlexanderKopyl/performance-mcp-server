<?php

declare(strict_types=1);

namespace App\Domain\Model;

final readonly class Span
{
    /**
     * @param list<EvidenceRef> $evidence
     */
    public function __construct(
        public string $type,
        public string $label,
        public float $selfMs,
        public float $totalMs,
        public array $evidence = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'self_ms' => $this->selfMs,
            'total_ms' => $this->totalMs,
            'evidence' => array_map(static fn (EvidenceRef $ref): array => $ref->toArray(), $this->evidence),
        ];
    }
}
