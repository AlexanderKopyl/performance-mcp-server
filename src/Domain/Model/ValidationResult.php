<?php

declare(strict_types=1);

namespace App\Domain\Model;

final readonly class ValidationResult
{
    /**
     * @param list<string> $errors
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $path,
        public bool $ok,
        public ?string $detectedType = null,
        public ?string $detectedVersion = null,
        public array $errors = [],
        public array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'ok' => $this->ok,
            'detected_type' => $this->detectedType,
            'detected_version' => $this->detectedVersion,
            'errors' => $this->errors,
            'metadata' => $this->metadata,
        ];
    }
}
