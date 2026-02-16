<?php

declare(strict_types=1);

namespace App\Application\Artifacts\Contract;

use App\Application\Artifacts\Dto\ParsedArtifact;
use App\Domain\Model\ArtifactDescriptor;
use App\Domain\Model\ValidationResult;

interface ArtifactFormatHandlerInterface
{
    public function formatType(): string;

    public function validate(ArtifactDescriptor $descriptor): ValidationResult;

    public function parse(ArtifactDescriptor $descriptor, ValidationResult $validation): ParsedArtifact;
}
