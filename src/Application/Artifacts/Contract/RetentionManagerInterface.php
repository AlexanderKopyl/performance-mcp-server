<?php

declare(strict_types=1);

namespace App\Application\Artifacts\Contract;

interface RetentionManagerInterface
{
    public function rotate(): void;
}
