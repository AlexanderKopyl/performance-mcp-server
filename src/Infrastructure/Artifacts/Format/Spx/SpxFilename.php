<?php

declare(strict_types=1);

namespace App\Infrastructure\Artifacts\Format\Spx;

final readonly class SpxFilename
{
    private function __construct(
        public string $basename,
        public string $prefix,
        public string $extension,
        public string $timestamp,
        public string $host,
        public int $pid,
        public int $runId,
        public string $jsonPath,
        public string $textGzPath,
    ) {
    }

    public static function tryParse(string $path): ?self
    {
        $basename = basename($path);
        if (preg_match(
            '/^(?<prefix>spx-full-(?<timestamp>\d{8}_\d{6})-(?<host>.+)-(?<pid>\d+)-(?<runid>\d+))\.(?<ext>json|txt\.gz)$/',
            $basename,
            $match,
        ) !== 1) {
            return null;
        }

        $directory = dirname($path);
        $prefix = (string) $match['prefix'];

        return new self(
            basename: $basename,
            prefix: $prefix,
            extension: (string) $match['ext'],
            timestamp: (string) $match['timestamp'],
            host: (string) $match['host'],
            pid: (int) $match['pid'],
            runId: (int) $match['runid'],
            jsonPath: $directory.DIRECTORY_SEPARATOR.$prefix.'.json',
            textGzPath: $directory.DIRECTORY_SEPARATOR.$prefix.'.txt.gz',
        );
    }

    public function metadata(bool $hasJson, bool $hasTextGz): array
    {
        $status = 'partial';
        if ($hasJson && $hasTextGz) {
            $status = 'paired';
        }

        return [
            'run' => [
                'prefix' => $this->prefix,
                'timestamp' => $this->timestamp,
                'host' => $this->host,
                'pid' => $this->pid,
                'runid' => $this->runId,
            ],
            'pairing' => [
                'status' => $status,
                'has_json' => $hasJson,
                'has_txt_gz' => $hasTextGz,
                'missing' => array_values(array_filter([
                    $hasJson ? null : 'json',
                    $hasTextGz ? null : 'txt.gz',
                ])),
                'counterpart_path' => $this->extension === 'json' ? $this->textGzPath : $this->jsonPath,
            ],
        ];
    }
}
