<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\Model\SnapshotId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class FilesystemStorageScaffold
{
    public function __construct(
        #[Autowire('%app.storage_base_dir%')]
        private string $baseDir,
    ) {
    }

    public function ensureInitialized(): void
    {
        foreach ([
            $this->baseDir,
            $this->snapshotsBaseDir(),
            $this->reportsBaseDir(),
            $this->bundlesBaseDir(),
            $this->indexesBaseDir(),
            $this->endpointIndexDir(),
            $this->queryIndexDir(),
        ] as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
        }
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }

    public function snapshotsBaseDir(): string
    {
        return $this->baseDir.'/snapshots';
    }

    public function reportsBaseDir(): string
    {
        return $this->baseDir.'/reports';
    }

    public function bundlesBaseDir(): string
    {
        return $this->baseDir.'/bundles';
    }

    public function indexesBaseDir(): string
    {
        return $this->baseDir.'/indexes';
    }

    public function endpointIndexDir(): string
    {
        return $this->indexesBaseDir().'/endpoints';
    }

    public function queryIndexDir(): string
    {
        return $this->indexesBaseDir().'/queries';
    }

    public function snapshotDirectory(SnapshotId $snapshotId): string
    {
        return sprintf('%s/snapshot_%s', $this->snapshotsBaseDir(), $snapshotId->value);
    }

    public function snapshotMetadataPath(SnapshotId $snapshotId): string
    {
        return $this->snapshotDirectory($snapshotId).'/metadata.json';
    }

    public function snapshotDataPath(SnapshotId $snapshotId): string
    {
        return $this->snapshotDirectory($snapshotId).'/snapshot.json';
    }

    public function snapshotsManifestPath(): string
    {
        return $this->snapshotsBaseDir().'/index.json';
    }
}
