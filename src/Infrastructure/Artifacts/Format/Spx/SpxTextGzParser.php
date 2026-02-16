<?php

declare(strict_types=1);

namespace App\Infrastructure\Artifacts\Format\Spx;

use App\Domain\Model\EvidenceRef;
use App\Domain\Model\RequestProfile;
use App\Domain\Model\Span;

final readonly class SpxTextGzParser implements SpxParserInterface
{
    public function __construct(private int $maxDecompressedBytes = 16_777_216)
    {
    }

    public function parse(string $path, array $validationMetadata): array
    {
        $handle = @gzopen($path, 'rb');
        if ($handle === false) {
            return ['profiles' => [], 'notes' => ['cannot open gz file']];
        }

        $lineNo = 0;
        $bytes = 0;
        $notes = [];
        $spans = [];
        $section = 'root';
        $record = 0;

        while (!gzeof($handle)) {
            $line = gzgets($handle);
            if (!is_string($line)) {
                break;
            }

            ++$lineNo;
            $bytes += strlen($line);
            if ($bytes > $this->maxDecompressedBytes) {
                $notes[] = sprintf('decompressed content exceeds %d bytes', $this->maxDecompressedBytes);
                break;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if ($this->isSectionMarker($trimmed)) {
                $section = $trimmed;
                continue;
            }

            $parsed = $this->parseLineAsSpan($trimmed);
            if ($parsed === null) {
                continue;
            }

            ++$record;
            $spans[] = new Span(
                type: 'php',
                label: $parsed['label'],
                selfMs: $parsed['self_ms'],
                totalMs: $parsed['total_ms'],
                evidence: [
                    new EvidenceRef(
                        source: 'spx',
                        file: $path,
                        lineRange: ['start' => $lineNo, 'end' => $lineNo],
                        recordId: sprintf('text:%d', $record),
                        extractionNote: sprintf('span metrics extracted from section %s', $section),
                    ),
                ],
            );
        }

        gzclose($handle);

        usort($spans, static fn (Span $a, Span $b): int => self::spanSortKey($a) <=> self::spanSortKey($b));

        $run = is_array($validationMetadata['run'] ?? null) ? $validationMetadata['run'] : [];
        $endpoint = sprintf(
            'spx://%s/%s/%s',
            is_string($run['host'] ?? null) ? $run['host'] : 'unknown-host',
            is_int($run['pid'] ?? null) ? (string) $run['pid'] : '0',
            is_int($run['runid'] ?? null) ? (string) $run['runid'] : '0',
        );

        $wallMs = 0.0;
        foreach ($spans as $span) {
            $wallMs = max($wallMs, $span->totalMs);
        }

        return [
            'profiles' => [
                new RequestProfile(
                    endpoint: $endpoint,
                    ttfbMs: null,
                    wallMs: $wallMs,
                    cpuMs: null,
                    memMb: null,
                    spans: $spans,
                    evidence: [
                        new EvidenceRef(
                            source: 'spx',
                            file: $path,
                            lineRange: ['start' => 1, 'end' => max(1, $lineNo)],
                            recordId: 'text:run',
                            extractionNote: 'request-level wall_ms inferred from maximum parsed span total_ms',
                        ),
                    ],
                ),
            ],
            'notes' => $notes,
        ];
    }

    private function isSectionMarker(string $line): bool
    {
        if (preg_match('/^\[[^\]]+\]$/', $line) === 1) {
            return true;
        }

        if (preg_match('/^={3,}.*={3,}$/', $line) === 1) {
            return true;
        }

        return preg_match('/^-{3,}.*-{3,}$/', $line) === 1;
    }

    /**
     * @return array{label:string,self_ms:float,total_ms:float}|null
     */
    private function parseLineAsSpan(string $line): ?array
    {
        if (preg_match('/^(?<label>[^|]+?)\|\s*self_ms\s*[:=]\s*(?<self>[\d.]+)\s*\|\s*total_ms\s*[:=]\s*(?<total>[\d.]+)$/i', $line, $m) === 1) {
            return [
                'label' => trim($m['label']),
                'self_ms' => (float) $m['self'],
                'total_ms' => (float) $m['total'],
            ];
        }

        if (preg_match('/^(?<label>[^,]+),\s*self_ms\s*[:=]\s*(?<self>[\d.]+),\s*total_ms\s*[:=]\s*(?<total>[\d.]+)$/i', $line, $m) === 1) {
            return [
                'label' => trim($m['label']),
                'self_ms' => (float) $m['self'],
                'total_ms' => (float) $m['total'],
            ];
        }

        if (preg_match('/^(?<label>\S.+?)\s+(?<self>[\d.]+)\s*ms\s+(?<total>[\d.]+)\s*ms$/i', $line, $m) === 1) {
            return [
                'label' => trim($m['label']),
                'self_ms' => (float) $m['self'],
                'total_ms' => (float) $m['total'],
            ];
        }

        return null;
    }

    private static function spanSortKey(Span $span): string
    {
        $recordId = '';
        if ($span->evidence !== []) {
            $recordId = (string) ($span->evidence[0]->recordId ?? '');
        }

        return sprintf('%s|%020.6f|%020.6f|%s', $span->label, $span->selfMs, $span->totalMs, $recordId);
    }
}
