<?php

declare(strict_types=1);

namespace App\Domain\Model;

final readonly class Recommendation
{
    /**
     * @param list<EvidenceRef> $evidence
     */
    public function __construct(
        public string $id,
        public string $action,
        public string $verificationStep,
        public array $evidence = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'verification_step' => $this->verificationStep,
            'evidence' => array_map(static fn (EvidenceRef $ref): array => $ref->toArray(), $this->evidence),
        ];
    }
}
