<?php

declare(strict_types=1);

namespace App\Application\Collector\Service;

use App\Shared\Util\CanonicalJson;
use RuntimeException;

final readonly class CollectorRunService
{
    public function __construct(
        private CollectorInputNormalizer $inputNormalizer,
        private HttpTimingProbe $timingProbe,
        private BundleWriter $bundleWriter,
        private BundleRetentionManager $bundleRetentionManager,
        private int $maxFilesPerSpxDir,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function run(array $params, string $correlationId): array
    {
        $config = $this->inputNormalizer->normalize($params);

        $bundle = $this->bundleWriter->initialize($config['output_dir'], $correlationId);
        $bundleDir = $bundle['bundle_dir'];

        $spxFiles = $this->discoverSpxFiles($config['spx_dirs']);
        $inventory = [];
        foreach ($spxFiles as $file) {
            $inventory[] = $this->bundleWriter->storeRawFile($file, 'spx', $bundleDir);
        }

        $inventory[] = $this->bundleWriter->storeRawFile($config['slow_log_path'], 'slowlog', $bundleDir);

        $probed = $this->timingProbe->probe(
            baseUrl: $config['base_url'],
            urlPaths: $config['url_paths'],
            sampleCount: $config['sample_count'],
            concurrency: $config['concurrency'],
            timeoutMs: $config['timeout_ms'],
            warmupCount: $config['warmup_count'],
            headers: $config['headers'],
            redactionRules: $config['redaction_rules'],
        );

        $timingRequests = [];
        foreach ($probed['samples'] as $sample) {
            $timingRequests[] = [
                'sample_id' => $sample['sample_id'] ?? null,
                'route' => $sample['path'] ?? null,
                'url' => $sample['url'] ?? null,
                'status' => $sample['status'] ?? null,
                'started_at' => $sample['started_at'] ?? null,
                'ended_at' => $sample['ended_at'] ?? null,
                'ttfb_ms' => $sample['ttfb_ms'] ?? null,
                'wall_ms' => $sample['total_ms'] ?? null,
                'raw_timings' => $sample['raw_timings'] ?? [],
                'error' => $sample['error'] ?? null,
            ];
        }

        $timingsPayload = [
            'format' => 'ttfb_timings',
            'version' => 'collector-v1',
            'collected_at' => gmdate(DATE_ATOM),
            'correlation_id' => $correlationId,
            'probe_method' => $probed['method'],
            'base_url' => $config['base_url'],
            'sample_count' => $config['sample_count'],
            'concurrency' => $config['concurrency'],
            'timeout_ms' => $config['timeout_ms'],
            'redaction_rules' => $config['redaction_rules'],
            'requests' => $timingRequests,
        ];

        $timingsPath = $this->bundleWriter->writeJson($bundleDir, 'timings.json', $timingsPayload);
        $timingsSha = hash_file('sha256', $timingsPath) ?: '';

        $manifestPayload = [
            'format' => 'collector_bundle',
            'version' => '1',
            'run' => [
                'bundle_id' => $bundle['bundle_id'],
                'tool' => 'collect.run',
                'correlation_id' => $correlationId,
                'created_at' => $bundle['created_at'],
            ],
            'config' => [
                'spx_dirs' => $config['spx_dirs'],
                'slow_log_path' => $config['slow_log_path'],
                'base_url' => $config['base_url'],
                'url_paths' => $config['url_paths'],
                'headers_allowlist' => $config['headers_allowlist'],
                'sample_count' => $config['sample_count'],
                'concurrency' => $config['concurrency'],
                'timeout_ms' => $config['timeout_ms'],
                'warmup_count' => $config['warmup_count'],
                'redaction_rules' => $config['redaction_rules'],
            ],
            'inventory' => $inventory,
            'outputs' => [
                'timings_json' => 'timings.json',
            ],
            'checksums' => [
                'timings_json_sha256' => $timingsSha,
                'inventory_sha256' => hash('sha256', CanonicalJson::encode($inventory)),
            ],
        ];

        $manifestPath = $this->bundleWriter->writeJson($bundleDir, 'manifest.json', $manifestPayload);

        $this->bundleRetentionManager->rotate(
            keepLastN: $config['retention']['keep_last_n'],
            ttlDays: $config['retention']['ttl_days'],
        );

        return [
            'bundle_id' => $bundle['bundle_id'],
            'bundle_dir' => $bundleDir,
            'manifest_path' => $manifestPath,
            'timings_path' => $timingsPath,
            'artifact_counts' => [
                'spx_files' => count($spxFiles),
                'inventory_items' => count($inventory),
                'timing_samples' => count($probed['samples']),
            ],
        ];
    }

    /**
     * @param list<string> $spxDirs
     * @return list<string>
     */
    private function discoverSpxFiles(array $spxDirs): array
    {
        $files = [];

        foreach ($spxDirs as $directory) {
            $countForDir = 0;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $item) {
                if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                    continue;
                }

                $files[] = $item->getPathname();
                ++$countForDir;

                if ($countForDir >= $this->maxFilesPerSpxDir) {
                    break;
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        if ($files === []) {
            throw new RuntimeException('No SPX files discovered under provided spx_dirs.');
        }

        return $files;
    }
}
