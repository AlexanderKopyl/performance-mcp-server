<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Application\Artifacts\Contract\RetentionManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RetentionManager implements RetentionManagerInterface
{
    public function __construct(
        private FilesystemStorageScaffold $scaffold,
        #[Autowire('%app.retention.keep_last_n%')]
        private ?int $keepLastN,
        #[Autowire('%app.retention.ttl_days%')]
        private ?int $ttlDays,
    ) {
    }

    public function rotate(): void
    {
        $this->scaffold->ensureInitialized();

        $snapshots = $this->listSnapshots();
        if ($snapshots === []) {
            return;
        }

        $toDelete = [];

        if ($this->ttlDays !== null && $this->ttlDays > 0) {
            $cutoffTs = strtotime(sprintf('-%d days', $this->ttlDays)) ?: 0;
            foreach ($snapshots as $snapshot) {
                if (($snapshot['created_at_ts'] ?? 0) < $cutoffTs) {
                    $toDelete[$snapshot['id']] = $snapshot;
                }
            }
        }

        if ($this->keepLastN !== null && $this->keepLastN >= 0 && count($snapshots) > $this->keepLastN) {
            $ordered = $snapshots;
            usort($ordered, static fn (array $a, array $b): int => ($b['created_at_ts'] <=> $a['created_at_ts']));
            $overflow = array_slice($ordered, $this->keepLastN);
            foreach ($overflow as $snapshot) {
                $toDelete[$snapshot['id']] = $snapshot;
            }
        }

        if ($toDelete === []) {
            return;
        }

        foreach ($toDelete as $snapshot) {
            $this->safeDeleteDirectory($snapshot['dir']);
        }

        $deletedIds = array_keys($toDelete);
        $this->cleanupManifest($deletedIds);
        $this->cleanupIndexes($deletedIds);
    }

    /**
     * @return list<array{id:string,dir:string,created_at_ts:int}>
     */
    private function listSnapshots(): array
    {
        $base = $this->scaffold->snapshotsBaseDir();
        $entries = scandir($base);
        if ($entries === false) {
            return [];
        }

        $snapshots = [];
        foreach ($entries as $entry) {
            if (!str_starts_with($entry, 'snapshot_')) {
                continue;
            }

            $dir = $base.'/'.$entry;
            if (!is_dir($dir)) {
                continue;
            }

            $id = substr($entry, strlen('snapshot_'));
            if ($id === false || $id === '') {
                continue;
            }

            $metadataPath = $dir.'/metadata.json';
            $createdAtTs = is_file($metadataPath)
                ? (strtotime((string) (json_decode((string) file_get_contents($metadataPath), true)['created_at'] ?? '')) ?: 0)
                : 0;

            $snapshots[] = ['id' => $id, 'dir' => $dir, 'created_at_ts' => $createdAtTs];
        }

        return $snapshots;
    }

    /**
     * @param list<string> $deletedIds
     */
    private function cleanupManifest(array $deletedIds): void
    {
        $path = $this->scaffold->snapshotsManifestPath();
        if (!is_file($path)) {
            return;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return;
        }

        $filtered = array_values(array_filter(
            $decoded,
            static fn ($row): bool => is_array($row) && !in_array((string) ($row['snapshot_id'] ?? ''), $deletedIds, true),
        ));

        file_put_contents($path, json_encode($filtered, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n", LOCK_EX);
    }

    /**
     * @param list<string> $deletedIds
     */
    private function cleanupIndexes(array $deletedIds): void
    {
        foreach ([$this->scaffold->endpointIndexDir(), $this->scaffold->queryIndexDir()] as $indexDir) {
            $files = glob($indexDir.'/*.json') ?: [];
            foreach ($files as $file) {
                $decoded = json_decode((string) file_get_contents($file), true);
                if (!is_array($decoded)) {
                    continue;
                }

                $snapshotIds = is_array($decoded['snapshot_ids'] ?? null) ? $decoded['snapshot_ids'] : [];
                $snapshotIds = array_values(array_filter(
                    $snapshotIds,
                    static fn ($id): bool => is_string($id) && !in_array($id, $deletedIds, true),
                ));

                if ($snapshotIds === []) {
                    @unlink($file);
                    continue;
                }

                $decoded['snapshot_ids'] = $snapshotIds;
                file_put_contents($file, json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n", LOCK_EX);
            }
        }
    }

    private function safeDeleteDirectory(string $path): void
    {
        $resolvedBase = realpath($this->scaffold->baseDir());
        $resolvedPath = realpath($path);

        if ($resolvedBase === false || $resolvedPath === false || !str_starts_with($resolvedPath, $resolvedBase.'/')) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolvedPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($resolvedPath);
    }
}
