<?php

declare(strict_types=1);

namespace App\Infrastructure\Artifacts\Format\Spx;

interface SpxParserInterface
{
    /**
     * @param array<string, mixed> $validationMetadata
     * @return array{profiles:list<\App\Domain\Model\RequestProfile>, notes:list<string>}
     */
    public function parse(string $path, array $validationMetadata): array;
}
