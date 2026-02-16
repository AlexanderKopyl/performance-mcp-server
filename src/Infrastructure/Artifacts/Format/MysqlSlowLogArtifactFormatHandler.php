<?php

declare(strict_types=1);

namespace App\Infrastructure\Artifacts\Format;

use App\Application\Artifacts\Contract\ArtifactFormatHandlerInterface;
use App\Application\Artifacts\Dto\ParsedArtifact;
use App\Domain\Model\ArtifactDescriptor;
use App\Domain\Model\DbQuerySample;
use App\Domain\Model\EvidenceRef;
use App\Domain\Model\SourceArtifact;
use App\Domain\Model\ValidationResult;
use App\Infrastructure\Artifacts\SqlFingerprint;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class MysqlSlowLogArtifactFormatHandler implements ArtifactFormatHandlerInterface
{
    public function __construct(
        private SqlFingerprint $sqlFingerprint,
        #[Autowire('%app.slow_log_max_examples_per_fingerprint%')]
        private int $maxExamplesPerFingerprint,
    ) {
    }

    public function formatType(): string
    {
        return 'mysql_slow_log';
    }

    public function validate(ArtifactDescriptor $descriptor): ValidationResult
    {
        $handle = @fopen($descriptor->path, 'rb');
        if ($handle === false) {
            return new ValidationResult($descriptor->path, false, errors: ['cannot read file']);
        }

        $lines = 0;
        $hasTime = false;
        $hasStats = false;
        $hasTimestamp = false;

        while (($line = fgets($handle)) !== false && $lines < 500) {
            ++$lines;
            $line = trim($line);

            if (str_starts_with($line, '# Time:')) {
                $hasTime = true;
            }
            if (str_starts_with($line, '# Query_time:')) {
                $hasStats = true;
            }
            if (str_starts_with($line, 'SET timestamp=')) {
                $hasTimestamp = true;
            }

            if ($hasTime && $hasStats && $hasTimestamp) {
                break;
            }
        }

        fclose($handle);

        if (!$hasTime || !$hasStats || !$hasTimestamp) {
            return new ValidationResult($descriptor->path, false, errors: ['missing required MySQL slow-log markers']);
        }

        return new ValidationResult(
            path: $descriptor->path,
            ok: true,
            detectedType: $this->formatType(),
            detectedVersion: 'mysql-slowlog-v1',
        );
    }

    public function parse(ArtifactDescriptor $descriptor, ValidationResult $validation): ParsedArtifact
    {
        $handle = fopen($descriptor->path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot open artifact: %s', $descriptor->path));
        }

        /** @var array<string, array{total_ms:float,count:int,lock_ms:float,rows_examined:float,examples:list<string>,evidence:list<EvidenceRef>}> $buckets */
        $buckets = [];

        $lineNo = 0;
        $recordNumber = 0;
        $current = null;

        $flushCurrent = function () use (&$current, &$buckets, &$recordNumber): void {
            if (!is_array($current)) {
                return;
            }

            $sql = trim((string) ($current['sql'] ?? ''));
            $queryTimeSec = (float) ($current['query_time_sec'] ?? 0.0);
            if ($sql === '' || $queryTimeSec <= 0.0) {
                $current = null;
                return;
            }

            ++$recordNumber;
            $fingerprint = $this->sqlFingerprint->fingerprint($sql);
            $example = $this->sqlFingerprint->redactSql($sql);

            $bucket = $buckets[$fingerprint] ?? [
                'total_ms' => 0.0,
                'count' => 0,
                'lock_ms' => 0.0,
                'rows_examined' => 0.0,
                'examples' => [],
                'evidence' => [],
            ];

            $bucket['total_ms'] += $queryTimeSec * 1000;
            $bucket['count'] += 1;
            $bucket['lock_ms'] += (float) ($current['lock_time_sec'] ?? 0.0) * 1000;
            $bucket['rows_examined'] += (float) ($current['rows_examined'] ?? 0.0);

            if (count($bucket['examples']) < $this->maxExamplesPerFingerprint) {
                $bucket['examples'][] = $example;
            }

            $bucket['evidence'][] = new EvidenceRef(
                source: $this->formatType(),
                file: (string) $current['path'],
                lineRange: [
                    'start' => (int) $current['start_line'],
                    'end' => (int) $current['end_line'],
                ],
                recordId: sprintf('slowlog:%d', $recordNumber),
                extractionNote: 'query_time, lock_time, rows_examined and normalized SQL extracted from slow-log record',
            );

            $buckets[$fingerprint] = $bucket;
            $current = null;
        };

        while (($line = fgets($handle)) !== false) {
            ++$lineNo;
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '# Time:')) {
                $flushCurrent();
                $current = [
                    'path' => $descriptor->path,
                    'start_line' => $lineNo,
                    'end_line' => $lineNo,
                    'query_time_sec' => 0.0,
                    'lock_time_sec' => 0.0,
                    'rows_examined' => 0.0,
                    'sql' => '',
                ];
                continue;
            }

            if (!is_array($current)) {
                continue;
            }

            $current['end_line'] = $lineNo;

            if (str_starts_with($trimmed, '# Query_time:')) {
                if (preg_match('/Query_time:\s*([\d.]+)/', $trimmed, $m) === 1) {
                    $current['query_time_sec'] = (float) $m[1];
                }
                if (preg_match('/Lock_time:\s*([\d.]+)/', $trimmed, $m) === 1) {
                    $current['lock_time_sec'] = (float) $m[1];
                }
                if (preg_match('/Rows_examined:\s*(\d+)/', $trimmed, $m) === 1) {
                    $current['rows_examined'] = (float) $m[1];
                }
                continue;
            }

            if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, 'SET timestamp=')) {
                continue;
            }

            if (str_starts_with(mb_strtolower($trimmed), 'use ')) {
                continue;
            }

            $current['sql'] .= rtrim($line)."\n";
        }

        fclose($handle);
        $flushCurrent();

        $samples = [];
        foreach ($buckets as $fingerprint => $bucket) {
            $count = max(1, $bucket['count']);
            $samples[] = new DbQuerySample(
                fingerprint: $fingerprint,
                totalTimeMs: round($bucket['total_ms'], 3),
                avgTimeMs: round($bucket['total_ms'] / $count, 3),
                count: $bucket['count'],
                lockMs: round($bucket['lock_ms'], 3),
                rowsExamined: round($bucket['rows_examined'], 3),
                examples: $bucket['examples'],
                evidence: $bucket['evidence'],
            );
        }

        return new ParsedArtifact(
            source: new SourceArtifact(
                path: $descriptor->path,
                type: $this->formatType(),
                version: $validation->detectedVersion,
                sha256: hash_file('sha256', $descriptor->path) ?: '',
                sizeBytes: (int) (filesize($descriptor->path) ?: 0),
                hints: $descriptor->hints,
            ),
            dbQuerySamples: $samples,
        );
    }
}
