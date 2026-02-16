<?php

declare(strict_types=1);

namespace App\Application\Collector\Service;

use App\Infrastructure\Storage\FilesystemStorageScaffold;

final readonly class BundleRetentionManager
{
    public function __construct(
        private FilesystemStorageScaffold $scaffold,
        private ?int $defaultKeepLastN,
        private ?int $defaultTtlDays,
    ) {
    }

    public function rotate(?int $keepLastN = null, ?int $ttlDays = null): void
    {
        $this->scaffold->ensureInitialized();

        $effectiveKeepLastN = $keepLastN ?? $this->defaultKeepLastN;
        $effectiveTtlDays = $ttlDays ?? $this->defaultTtlDays;

        $bundles = $this->listBundles();
        if ($bundles === []) {
            return;
        }

        $toDelete = [];

        if ($effectiveTtlDays !== null && $effectiveTtlDays > 0) {
            $cutoffTs = strtotime(sprintf('-%d days', $effectiveTtlDays)) ?: 0;
            foreach ($bundles as $bundle) {
                if ($bundle['created_at_ts'] < $cutoffTs) {
                    $toDelete[$bundle['dir']] = $bundle['dir'];
                }
            }
        }

        if ($effectiveKeepLastN !== null && $effectiveKeepLastN >= 0 && count($bundles) > $effectiveKeepLastN) {
            $ordered = $bundles;
            usort($ordered, static fn (array $a, array $b): int => ($b['created_at_ts'] <=> $a['created_at_ts']));
            $overflow = array_slice($ordered, $effectiveKeepLastN);
            foreach ($overflow as $bundle) {
                $toDelete[$bundle['dir']] = $bundle['dir'];
            }
        }

        foreach ($toDelete as $directory) {
            $this->safeDeleteDirectory($directory);
        }
    }

    /**
     * @return list<array{dir:string,created_at_ts:int}>
     */
    private function listBundles(): array
    {
        $base = $this->scaffold->bundlesBaseDir();
        $entries = scandir($base);
        if ($entries === false) {
            return [];
        }

        $bundles = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $dir = $base.'/'.$entry;
            if (!is_dir($dir)) {
                continue;
            }

            $manifestPath = $dir.'/manifest.json';
            $createdAtTs = is_file($manifestPath)
                ? $this->createdAtFromManifest($manifestPath)
                : (filemtime($dir) ?: 0);

            $bundles[] = [
                'dir' => $dir,
                'created_at_ts' => $createdAtTs,
            ];
        }

        return $bundles;
    }

    private function createdAtFromManifest(string $manifestPath): int
    {
        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($decoded)) {
            return 0;
        }

        $createdAt = $decoded['run']['created_at'] ?? null;
        if (!is_string($createdAt) || trim($createdAt) === '') {
            return 0;
        }

        return strtotime($createdAt) ?: 0;
    }

    private function safeDeleteDirectory(string $path): void
    {
        $resolvedBase = realpath($this->scaffold->bundlesBaseDir());
        $resolvedPath = realpath($path);

        if ($resolvedBase === false || $resolvedPath === false) {
            return;
        }

        if (!str_starts_with($resolvedPath, $resolvedBase.'/')) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolvedPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($resolvedPath);
    }
}
