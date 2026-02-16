<?php

declare(strict_types=1);

namespace App\Application\Artifacts\Contract;

use App\Domain\Model\Snapshot;
use App\Domain\Model\SnapshotId;

interface SnapshotStoreInterface
{
    /**
     * @param array<string, scalar|array<array-key, scalar|null>|null> $environmentHints
     */
    public function persist(Snapshot $snapshot, array $environmentHints = []): void;

    public function load(SnapshotId $snapshotId): ?Snapshot;
}
