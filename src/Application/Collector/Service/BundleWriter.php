<?php

declare(strict_types=1);

namespace App\Application\Collector\Service;

use App\Infrastructure\Storage\FilesystemStorageScaffold;
use RuntimeException;

final readonly class BundleWriter
{
    public function __construct(
        private FilesystemStorageScaffold $scaffold,
        private int $copyMaxBytes,
    ) {
    }

    /**
     * @return array{bundle_id:string,bundle_dir:string,created_at:string}
     */
    public function initialize(?string $outputDir, string $correlationId): array
    {
        $this->scaffold->ensureInitialized();

        $createdAt = gmdate(DATE_ATOM);
        $bundleId = sprintf(
            'bundle_%s_%s',
            gmdate('Ymd_His'),
            substr(hash('sha256', $correlationId.'|'.microtime(true).'|'.random_int(1, PHP_INT_MAX)), 0, 10),
        );

        $bundleDir = $this->resolveBundleDirectory($outputDir, $bundleId);
        $this->ensureDirectory($bundleDir);
        $this->ensureDirectory($bundleDir.'/raw');
        $this->ensureDirectory($bundleDir.'/raw/spx');
        $this->ensureDirectory($bundleDir.'/raw/slowlog');

        return [
            'bundle_id' => $bundleId,
            'bundle_dir' => $bundleDir,
            'created_at' => $createdAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function storeRawFile(string $sourcePath, string $group, string $bundleDir): array
    {
        $size = (int) (filesize($sourcePath) ?: 0);
        $sha256 = hash_file('sha256', $sourcePath) ?: '';

        $relativeStoredPath = null;
        $mode = 'reference';

        if ($size <= $this->copyMaxBytes) {
            $destination = $this->uniqueDestinationPath($bundleDir.'/raw/'.$group, basename($sourcePath));
            if (!@copy($sourcePath, $destination)) {
                throw new RuntimeException(sprintf('Failed to copy artifact file: %s', $sourcePath));
            }

            $relativeStoredPath = $this->relativePathFromBundle($bundleDir, $destination);
            $mode = 'copied';
        }

        return [
            'type' => $group === 'spx' ? 'spx' : 'mysql_slow_log',
            'source_path' => $sourcePath,
            'storage_mode' => $mode,
            'stored_path' => $relativeStoredPath,
            'sha256' => $sha256,
            'size_bytes' => $size,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function writeJson(string $bundleDir, string $filename, array $payload): string
    {
        $path = $bundleDir.'/'.$filename;
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($path, $encoded."\n", LOCK_EX);

        return $path;
    }

    public function relativePathFromBundle(string $bundleDir, string $absolutePath): string
    {
        $prefix = rtrim($bundleDir, '/').'/';
        if (str_starts_with($absolutePath, $prefix)) {
            return substr($absolutePath, strlen($prefix));
        }

        return $absolutePath;
    }

    private function resolveBundleDirectory(?string $outputDir, string $bundleId): string
    {
        $base = $this->scaffold->bundlesBaseDir();

        if ($outputDir === null || trim($outputDir) === '') {
            return $base.'/'.$bundleId;
        }

        $trimmed = trim($outputDir);
        if ($trimmed[0] === '/') {
            $candidate = $trimmed;
        } else {
            $candidate = $base.'/'.$trimmed;
        }

        $resolvedBase = realpath($base);
        if ($resolvedBase === false) {
            throw new RuntimeException('Bundles base directory could not be resolved.');
        }

        $normalizedCandidate = $this->normalizePath($candidate);
        if (!str_starts_with($normalizedCandidate, $resolvedBase.'/') && $normalizedCandidate !== $resolvedBase) {
            throw new RuntimeException('output_dir must be inside the collector bundle storage directory.');
        }

        return $normalizedCandidate;
    }

    private function normalizePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);
                continue;
            }

            $parts[] = $part;
        }

        return '/'.implode('/', $parts);
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Failed to create directory: %s', $path));
        }
    }

    private function uniqueDestinationPath(string $directory, string $basename): string
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename) ?? 'artifact.dat';
        $candidate = $directory.'/'.$safeName;
        if (!is_file($candidate)) {
            return $candidate;
        }

        $dotPos = strrpos($safeName, '.');
        $name = $dotPos === false ? $safeName : substr($safeName, 0, $dotPos);
        $ext = $dotPos === false ? '' : substr($safeName, $dotPos);

        $counter = 1;
        do {
            $candidate = sprintf('%s/%s_%d%s', $directory, $name, $counter, $ext);
            ++$counter;
        } while (is_file($candidate));

        return $candidate;
    }
}
