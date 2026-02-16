<?php

declare(strict_types=1);

namespace App\Infrastructure\Artifacts\Format;

use App\Application\Artifacts\Contract\ArtifactFormatHandlerInterface;
use App\Application\Artifacts\Dto\ParsedArtifact;
use App\Domain\Model\ArtifactDescriptor;
use App\Domain\Model\SourceArtifact;
use App\Domain\Model\ValidationResult;
use App\Infrastructure\Artifacts\Format\Spx\SpxFilename;
use App\Infrastructure\Artifacts\Format\Spx\SpxJsonParser;
use App\Infrastructure\Artifacts\Format\Spx\SpxTextGzParser;
use JsonException;

final readonly class SpxArtifactFormatHandler implements ArtifactFormatHandlerInterface
{
    public function __construct(
        private SpxJsonParser $jsonParser,
        private SpxTextGzParser $textGzParser,
        private int $maxTextGzBytes = 16_777_216,
    ) {
    }

    public function formatType(): string
    {
        return 'spx';
    }

    public function validate(ArtifactDescriptor $descriptor): ValidationResult
    {
        $filename = SpxFilename::tryParse($descriptor->path);
        if ($filename === null) {
            return new ValidationResult($descriptor->path, false, errors: ['unsupported SPX filename signature']);
        }

        $hasJson = is_file($filename->jsonPath);
        $hasTextGz = is_file($filename->textGzPath);
        $metadata = $filename->metadata($hasJson, $hasTextGz);

        if ($filename->extension === 'json') {
            $content = @file_get_contents($descriptor->path);
            if ($content === false) {
                return new ValidationResult($descriptor->path, false, errors: ['cannot read file'], metadata: $metadata);
            }

            try {
                $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return new ValidationResult($descriptor->path, false, errors: ['invalid json'], metadata: $metadata);
            }

            if (!is_array($decoded)) {
                return new ValidationResult($descriptor->path, false, errors: ['spx json root must be object/array'], metadata: $metadata);
            }

            return new ValidationResult(
                path: $descriptor->path,
                ok: true,
                detectedType: $this->formatType(),
                detectedVersion: 'spx-json-v2',
                metadata: $metadata,
            );
        }

        $handle = @gzopen($descriptor->path, 'rb');
        if ($handle === false) {
            return new ValidationResult($descriptor->path, false, errors: ['cannot open gzip stream'], metadata: $metadata);
        }

        $probe = gzread($handle, 256);
        $decompressedBytes = is_string($probe) ? strlen($probe) : 0;
        while (!gzeof($handle) && $decompressedBytes <= $this->maxTextGzBytes) {
            $chunk = gzread($handle, 8192);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            $decompressedBytes += strlen($chunk);
        }
        gzclose($handle);

        if (!is_string($probe)) {
            return new ValidationResult($descriptor->path, false, errors: ['cannot read gzip stream'], metadata: $metadata);
        }
        if ($decompressedBytes > $this->maxTextGzBytes) {
            return new ValidationResult(
                $descriptor->path,
                false,
                errors: [sprintf('decompressed content exceeds %d bytes', $this->maxTextGzBytes)],
                metadata: $metadata,
            );
        }

        return new ValidationResult(
            path: $descriptor->path,
            ok: true,
            detectedType: $this->formatType(),
            detectedVersion: 'spx-text-gz-v1',
            metadata: $metadata,
        );
    }

    public function parse(ArtifactDescriptor $descriptor, ValidationResult $validation): ParsedArtifact
    {
        $metadata = $validation->metadata;
        $profiles = [];
        $notes = [];

        if ($validation->detectedVersion === 'spx-json-v2') {
            $parsed = $this->jsonParser->parse($descriptor->path, $metadata);
            $profiles = $parsed['profiles'];
            $notes = $parsed['notes'];
        } elseif ($validation->detectedVersion === 'spx-text-gz-v1') {
            $parsed = $this->textGzParser->parse($descriptor->path, $metadata);
            $profiles = $parsed['profiles'];
            $notes = $parsed['notes'];
        }

        if ($notes !== []) {
            $metadata['parse_notes'] = $notes;
        }

        return new ParsedArtifact(
            source: new SourceArtifact(
                path: $descriptor->path,
                type: $this->formatType(),
                version: $validation->detectedVersion,
                sha256: hash_file('sha256', $descriptor->path) ?: '',
                sizeBytes: (int) (filesize($descriptor->path) ?: 0),
                hints: array_merge($descriptor->hints, ['spx' => $metadata]),
            ),
            requestProfiles: $profiles,
        );
    }
}
