<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Application\Reporting\Contract\ReportWriterInterface;
use App\Shared\Util\CanonicalJson;

final readonly class FilesystemReportWriter implements ReportWriterInterface
{
    public function __construct(private FilesystemStorageScaffold $scaffold)
    {
    }

    public function write(string $reportId, string $markdown, array $jsonPayload): array
    {
        $this->scaffold->ensureInitialized();

        $base = $this->scaffold->reportsBaseDir();
        $markdownPath = sprintf('%s/report_%s.md', $base, $reportId);
        $jsonPath = sprintf('%s/report_%s.json', $base, $reportId);

        file_put_contents($markdownPath, rtrim($markdown)."\n", LOCK_EX);
        file_put_contents($jsonPath, CanonicalJson::encode($jsonPayload)."\n", LOCK_EX);

        return [
            'markdown_path' => $markdownPath,
            'json_path' => $jsonPath,
        ];
    }
}
