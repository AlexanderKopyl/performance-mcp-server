<?php

declare(strict_types=1);

namespace App\Infrastructure\Artifacts\Format;

use App\Application\Artifacts\Contract\ArtifactFormatHandlerInterface;
use App\Application\Artifacts\Dto\ParsedArtifact;
use App\Domain\Model\ArtifactDescriptor;
use App\Domain\Model\EvidenceRef;
use App\Domain\Model\RequestProfile;
use App\Domain\Model\SourceArtifact;
use App\Domain\Model\ValidationResult;
use JsonException;

final class TtfbTimingsArtifactFormatHandler implements ArtifactFormatHandlerInterface
{
    private const CSV_HEADERS = ['url', 'route', 'ttfb_ms', 'wall_ms', 'cpu_ms', 'mem_mb'];

    public function formatType(): string
    {
        return 'ttfb_timings';
    }

    public function validate(ArtifactDescriptor $descriptor): ValidationResult
    {
        $content = @file_get_contents($descriptor->path);
        if ($content === false) {
            return new ValidationResult($descriptor->path, false, errors: ['cannot read file']);
        }

        $trimmed = trim($content);
        if ($trimmed === '') {
            return new ValidationResult($descriptor->path, false, errors: ['empty file']);
        }

        if ($trimmed[0] === '{' || $trimmed[0] === '[') {
            try {
                $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return new ValidationResult($descriptor->path, false, errors: ['invalid json']);
            }

            if (
                is_array($decoded)
                && ($decoded['format'] ?? null) === 'ttfb_timings'
                && is_string($decoded['version'] ?? null)
                && is_array($decoded['requests'] ?? null)
            ) {
                return new ValidationResult(
                    path: $descriptor->path,
                    ok: true,
                    detectedType: $this->formatType(),
                    detectedVersion: (string) $decoded['version'],
                );
            }

            return new ValidationResult($descriptor->path, false, errors: ['unsupported timings json signature']);
        }

        $firstLine = strtok($content, "\n");
        if (!is_string($firstLine)) {
            return new ValidationResult($descriptor->path, false, errors: ['cannot read header']);
        }

        $header = str_getcsv(trim($firstLine));
        if ($header !== self::CSV_HEADERS) {
            return new ValidationResult($descriptor->path, false, errors: ['unsupported timings csv header']);
        }

        return new ValidationResult(
            path: $descriptor->path,
            ok: true,
            detectedType: $this->formatType(),
            detectedVersion: 'csv-v1',
        );
    }

    public function parse(ArtifactDescriptor $descriptor, ValidationResult $validation): ParsedArtifact
    {
        $profiles = [];

        if ($validation->detectedVersion === 'csv-v1') {
            $handle = fopen($descriptor->path, 'rb');
            if ($handle === false) {
                throw new \RuntimeException(sprintf('Cannot open artifact: %s', $descriptor->path));
            }

            $lineNumber = 0;
            while (($row = fgetcsv($handle)) !== false) {
                ++$lineNumber;
                if ($lineNumber === 1) {
                    continue;
                }

                if (!is_array($row) || count($row) !== 6) {
                    continue;
                }

                [$url, $route, $ttfb, $wall, $cpu, $mem] = $row;
                $endpoint = trim($route) !== '' ? trim($route) : trim($url);
                if ($endpoint === '') {
                    $endpoint = 'unknown_endpoint';
                }

                $profiles[] = new RequestProfile(
                    endpoint: $endpoint,
                    ttfbMs: is_numeric($ttfb) ? (float) $ttfb : null,
                    wallMs: is_numeric($wall) ? (float) $wall : 0.0,
                    cpuMs: is_numeric($cpu) ? (float) $cpu : null,
                    memMb: is_numeric($mem) ? (float) $mem : null,
                    evidence: [
                        new EvidenceRef(
                            source: $this->formatType(),
                            file: $descriptor->path,
                            lineRange: ['start' => $lineNumber, 'end' => $lineNumber],
                            recordId: sprintf('timings-csv:%d', $lineNumber - 1),
                            extractionNote: 'ttfb_ms, wall_ms, cpu_ms and mem_mb extracted from csv row',
                        ),
                    ],
                );
            }

            fclose($handle);
        } else {
            $decoded = json_decode((string) file_get_contents($descriptor->path), true, 512, JSON_THROW_ON_ERROR);
            $requests = is_array($decoded['requests'] ?? null) ? $decoded['requests'] : [];

            foreach ($requests as $index => $request) {
                if (!is_array($request)) {
                    continue;
                }

                $endpoint = (string) ($request['route'] ?? $request['url'] ?? 'unknown_endpoint');
                $profiles[] = new RequestProfile(
                    endpoint: $endpoint,
                    ttfbMs: is_numeric($request['ttfb_ms'] ?? null) ? (float) $request['ttfb_ms'] : null,
                    wallMs: is_numeric($request['wall_ms'] ?? null) ? (float) $request['wall_ms'] : 0.0,
                    cpuMs: is_numeric($request['cpu_ms'] ?? null) ? (float) $request['cpu_ms'] : null,
                    memMb: is_numeric($request['mem_mb'] ?? null) ? (float) $request['mem_mb'] : null,
                    evidence: [
                        new EvidenceRef(
                            source: $this->formatType(),
                            file: $descriptor->path,
                            lineRange: null,
                            recordId: sprintf('timings-json:%d', $index),
                            extractionNote: 'ttfb_ms, wall_ms, cpu_ms and mem_mb extracted from request object',
                        ),
                    ],
                );
            }
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
            requestProfiles: $profiles,
        );
    }
}
