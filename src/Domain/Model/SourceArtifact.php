<?php

declare(strict_types=1);

namespace App\Domain\Model;

final readonly class SourceArtifact
{
    /**
     * @param array<string, scalar|array<array-key, scalar|null>|null> $hints
     */
    public function __construct(
        public string $path,
        public string $type,
        public ?string $version,
        public string $sha256,
        public int $sizeBytes,
        public array $hints = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'type' => $this->type,
            'version' => $this->version,
            'sha256' => $this->sha256,
            'size_bytes' => $this->sizeBytes,
            'hints' => $this->hints,
        ];
    }
}
