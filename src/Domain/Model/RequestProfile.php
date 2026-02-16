<?php

declare(strict_types=1);

namespace App\Domain\Model;

final readonly class RequestProfile
{
    /**
     * @param list<Span> $spans
     * @param list<EvidenceRef> $evidence
     */
    public function __construct(
        public string $endpoint,
        public ?float $ttfbMs,
        public float $wallMs,
        public ?float $cpuMs,
        public ?float $memMb,
        public array $spans = [],
        public array $evidence = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'ttfb_ms' => $this->ttfbMs,
            'wall_ms' => $this->wallMs,
            'cpu_ms' => $this->cpuMs,
            'mem_mb' => $this->memMb,
            'spans' => array_map(static fn (Span $span): array => $span->toArray(), $this->spans),
            'evidence' => array_map(static fn (EvidenceRef $ref): array => $ref->toArray(), $this->evidence),
        ];
    }
}
