<?php

declare(strict_types=1);

namespace App\Domain\Model;

final readonly class ArtifactDescriptor
{
    /**
     * @param array<string, scalar|array<array-key, scalar|null>|null> $hints
     */
    public function __construct(
        public string $path,
        public array $hints = [],
    ) {
    }
}
