<?php

declare(strict_types=1);

namespace App\Application\Reporting\Contract;

interface ReportWriterInterface
{
    /**
     * @param array<string, mixed> $jsonPayload
     * @return array{markdown_path:string,json_path:string}
     */
    public function write(string $reportId, string $markdown, array $jsonPayload): array;
}
