<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Application\Artifacts\Contract\SnapshotStoreInterface;
use App\Domain\Model\DbQuerySample;
use App\Domain\Model\EvidenceRef;
use App\Domain\Model\RequestProfile;
use App\Domain\Model\Snapshot;
use App\Domain\Model\SnapshotId;
use App\Domain\Model\SourceArtifact;
use App\Domain\Model\Span;
use App\Shared\Util\CanonicalJson;
use JsonException;

final readonly class FilesystemSnapshotStore implements SnapshotStoreInterface
{
    public function __construct(private FilesystemStorageScaffold $scaffold)
    {
    }

    public function persist(Snapshot $snapshot, array $environmentHints = []): void
    {
        $this->scaffold->ensureInitialized();

        $snapshotDir = $this->scaffold->snapshotDirectory($snapshot->id);
        $isNew = !is_dir($snapshotDir);

        if ($isNew && !mkdir($snapshotDir, 0775, true) && !is_dir($snapshotDir)) {
            throw new \RuntimeException(sprintf('Unable to create snapshot directory: %s', $snapshotDir));
        }

        if ($isNew) {
            $this->writeJson($this->scaffold->snapshotDataPath($snapshot->id), $snapshot->toArray());
            $this->writeJson($this->scaffold->snapshotMetadataPath($snapshot->id), [
                'snapshot_id' => $snapshot->id->value,
                'created_at' => gmdate(DATE_ATOM),
                'sources' => array_map(static fn ($source): array => $source->toArray(), $snapshot->sources),
                'environment_hints' => $environmentHints,
            ]);
        }

        $this->upsertManifest($snapshot->id->value);
        $this->updateIndexes($snapshot);
    }

    public function load(SnapshotId $snapshotId): ?Snapshot
    {
        $data = $this->readJsonFile($this->scaffold->snapshotDataPath($snapshotId));
        if (!is_array($data)) {
            return null;
        }

        return $this->hydrateSnapshot($data);
    }

    private function upsertManifest(string $snapshotId): void
    {
        $path = $this->scaffold->snapshotsManifestPath();
        $manifest = $this->readJsonFile($path);
        if (!is_array($manifest)) {
            $manifest = [];
        }

        $manifest[] = ['snapshot_id' => $snapshotId, 'ingested_at' => gmdate(DATE_ATOM)];

        $byId = [];
        foreach ($manifest as $row) {
            if (!is_array($row) || !is_string($row['snapshot_id'] ?? null)) {
                continue;
            }

            $byId[$row['snapshot_id']] = $row;
        }

        $this->writeJson($path, array_values($byId));
    }

    private function updateIndexes(Snapshot $snapshot): void
    {
        foreach ($snapshot->requestProfiles as $profile) {
            $this->upsertEndpointIndex($snapshot->id->value, $profile);
        }

        foreach ($snapshot->dbQuerySamples as $sample) {
            $this->upsertQueryIndex($snapshot->id->value, $sample);
        }
    }

    private function upsertEndpointIndex(string $snapshotId, RequestProfile $profile): void
    {
        $key = hash('sha1', $profile->endpoint);
        $path = $this->scaffold->endpointIndexDir().'/'.$key.'.json';

        $data = $this->readJsonFile($path);
        if (!is_array($data)) {
            $data = [
                'endpoint' => $profile->endpoint,
                'snapshot_ids' => [],
            ];
        }

        $snapshotIds = is_array($data['snapshot_ids'] ?? null) ? $data['snapshot_ids'] : [];
        $snapshotIds[] = $snapshotId;
        $snapshotIds = array_values(array_unique(array_filter($snapshotIds, static fn ($id): bool => is_string($id))));

        $data['snapshot_ids'] = $snapshotIds;
        $data['updated_at'] = gmdate(DATE_ATOM);

        $this->writeJson($path, $data);
    }

    private function upsertQueryIndex(string $snapshotId, DbQuerySample $sample): void
    {
        $path = $this->scaffold->queryIndexDir().'/'.$sample->fingerprint.'.json';

        $data = $this->readJsonFile($path);
        if (!is_array($data)) {
            $data = [
                'fingerprint' => $sample->fingerprint,
                'snapshot_ids' => [],
            ];
        }

        $snapshotIds = is_array($data['snapshot_ids'] ?? null) ? $data['snapshot_ids'] : [];
        $snapshotIds[] = $snapshotId;
        $snapshotIds = array_values(array_unique(array_filter($snapshotIds, static fn ($id): bool => is_string($id))));

        $data['snapshot_ids'] = $snapshotIds;
        $data['updated_at'] = gmdate(DATE_ATOM);

        $this->writeJson($path, $data);
    }

    /**
     * @return mixed
     */
    private function readJsonFile(string $path): mixed
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        $json = CanonicalJson::encode($payload);
        file_put_contents($path, $json."\n", LOCK_EX);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hydrateSnapshot(array $payload): ?Snapshot
    {
        $id = $payload['id'] ?? null;
        $collectedAt = $payload['collected_at'] ?? null;
        $sources = $payload['sources'] ?? null;
        $requestProfiles = $payload['request_profiles'] ?? null;
        $dbQuerySamples = $payload['db_query_samples'] ?? null;

        if (
            !is_string($id)
            || !is_string($collectedAt)
            || !is_array($sources)
            || !is_array($requestProfiles)
            || !is_array($dbQuerySamples)
        ) {
            return null;
        }

        $sourceModels = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $model = $this->hydrateSource($source);
            if ($model !== null) {
                $sourceModels[] = $model;
            }
        }

        $profileModels = [];
        foreach ($requestProfiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }
            $model = $this->hydrateRequestProfile($profile);
            if ($model !== null) {
                $profileModels[] = $model;
            }
        }

        $queryModels = [];
        foreach ($dbQuerySamples as $sample) {
            if (!is_array($sample)) {
                continue;
            }
            $model = $this->hydrateDbQuerySample($sample);
            if ($model !== null) {
                $queryModels[] = $model;
            }
        }

        return new Snapshot(
            id: new SnapshotId($id),
            collectedAt: $collectedAt,
            sources: $sourceModels,
            requestProfiles: $profileModels,
            dbQuerySamples: $queryModels,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hydrateSource(array $payload): ?SourceArtifact
    {
        $path = $payload['path'] ?? null;
        $type = $payload['type'] ?? null;
        $version = $payload['version'] ?? null;
        $sha256 = $payload['sha256'] ?? null;
        $sizeBytes = $payload['size_bytes'] ?? null;
        $hints = $payload['hints'] ?? [];

        if (
            !is_string($path)
            || !is_string($type)
            || ($version !== null && !is_string($version))
            || !is_string($sha256)
            || !is_int($sizeBytes)
            || !is_array($hints)
        ) {
            return null;
        }

        return new SourceArtifact(
            path: $path,
            type: $type,
            version: $version,
            sha256: $sha256,
            sizeBytes: $sizeBytes,
            hints: $hints,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hydrateRequestProfile(array $payload): ?RequestProfile
    {
        $endpoint = $payload['endpoint'] ?? null;
        $ttfbMs = $payload['ttfb_ms'] ?? null;
        $wallMs = $payload['wall_ms'] ?? null;
        $cpuMs = $payload['cpu_ms'] ?? null;
        $memMb = $payload['mem_mb'] ?? null;
        $spans = $payload['spans'] ?? [];
        $evidence = $payload['evidence'] ?? [];

        if (
            !is_string($endpoint)
            || !is_numeric($wallMs)
            || ($ttfbMs !== null && !is_numeric($ttfbMs))
            || ($cpuMs !== null && !is_numeric($cpuMs))
            || ($memMb !== null && !is_numeric($memMb))
            || !is_array($spans)
            || !is_array($evidence)
        ) {
            return null;
        }

        $spanModels = [];
        foreach ($spans as $span) {
            if (!is_array($span)) {
                continue;
            }
            $model = $this->hydrateSpan($span);
            if ($model !== null) {
                $spanModels[] = $model;
            }
        }

        return new RequestProfile(
            endpoint: $endpoint,
            ttfbMs: $ttfbMs !== null ? (float) $ttfbMs : null,
            wallMs: (float) $wallMs,
            cpuMs: $cpuMs !== null ? (float) $cpuMs : null,
            memMb: $memMb !== null ? (float) $memMb : null,
            spans: $spanModels,
            evidence: $this->hydrateEvidenceList($evidence),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hydrateDbQuerySample(array $payload): ?DbQuerySample
    {
        $fingerprint = $payload['fingerprint'] ?? null;
        $totalTimeMs = $payload['total_time_ms'] ?? null;
        $avgTimeMs = $payload['avg_time_ms'] ?? null;
        $count = $payload['count'] ?? null;
        $lockMs = $payload['lock_ms'] ?? null;
        $rowsExamined = $payload['rows_examined'] ?? null;
        $examples = $payload['examples'] ?? [];
        $evidence = $payload['evidence'] ?? [];

        if (
            !is_string($fingerprint)
            || !is_numeric($totalTimeMs)
            || !is_numeric($avgTimeMs)
            || !is_int($count)
            || ($lockMs !== null && !is_numeric($lockMs))
            || ($rowsExamined !== null && !is_numeric($rowsExamined))
            || !is_array($examples)
            || !is_array($evidence)
        ) {
            return null;
        }

        $examples = array_values(array_filter($examples, static fn (mixed $value): bool => is_string($value)));

        return new DbQuerySample(
            fingerprint: $fingerprint,
            totalTimeMs: (float) $totalTimeMs,
            avgTimeMs: (float) $avgTimeMs,
            count: $count,
            lockMs: $lockMs !== null ? (float) $lockMs : null,
            rowsExamined: $rowsExamined !== null ? (float) $rowsExamined : null,
            examples: $examples,
            evidence: $this->hydrateEvidenceList($evidence),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hydrateSpan(array $payload): ?Span
    {
        $type = $payload['type'] ?? null;
        $label = $payload['label'] ?? null;
        $selfMs = $payload['self_ms'] ?? null;
        $totalMs = $payload['total_ms'] ?? null;
        $evidence = $payload['evidence'] ?? [];

        if (
            !is_string($type)
            || !is_string($label)
            || !is_numeric($selfMs)
            || !is_numeric($totalMs)
            || !is_array($evidence)
        ) {
            return null;
        }

        return new Span(
            type: $type,
            label: $label,
            selfMs: (float) $selfMs,
            totalMs: (float) $totalMs,
            evidence: $this->hydrateEvidenceList($evidence),
        );
    }

    /**
     * @param list<mixed> $items
     * @return list<EvidenceRef>
     */
    private function hydrateEvidenceList(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $source = $item['source'] ?? null;
            $file = $item['file'] ?? null;
            $lineRange = $item['line_range'] ?? null;
            $recordId = $item['record_id'] ?? null;
            $extractionNote = $item['extraction_note'] ?? null;

            if (
                !is_string($source)
                || !is_string($file)
                || ($lineRange !== null && !is_array($lineRange))
                || ($recordId !== null && !is_string($recordId))
                || !is_string($extractionNote)
            ) {
                continue;
            }

            $normalizedLineRange = null;
            if ($lineRange !== null && isset($lineRange['start'], $lineRange['end']) && is_int($lineRange['start']) && is_int($lineRange['end'])) {
                $normalizedLineRange = ['start' => $lineRange['start'], 'end' => $lineRange['end']];
            }

            $result[] = new EvidenceRef(
                source: $source,
                file: $file,
                lineRange: $normalizedLineRange,
                recordId: $recordId,
                extractionNote: $extractionNote,
            );
        }

        return $result;
    }
}
