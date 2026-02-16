<?php

declare(strict_types=1);

namespace App\Domain\Model;

final readonly class EvidenceRef
{
    /**
     * @param array{start:int,end:int}|null $lineRange
     */
    public function __construct(
        public string $source,
        public string $file,
        public ?array $lineRange,
        public ?string $recordId,
        public string $extractionNote,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'file' => $this->file,
            'line_range' => $this->lineRange,
            'record_id' => $this->recordId,
            'extraction_note' => $this->extractionNote,
        ];
    }
}
