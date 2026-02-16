<?php

declare(strict_types=1);

namespace App\Domain\Model;

final readonly class SnapshotId
{
    public function __construct(public string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
