<?php

declare(strict_types=1);

namespace App\Application\Artifacts\Service;

use App\Application\Artifacts\Contract\RetentionManagerInterface;
use App\Application\Artifacts\Contract\SnapshotStoreInterface;
use App\Application\Artifacts\Dto\ParsedArtifact;
use App\Domain\Model\ArtifactDescriptor;
use App\Domain\Model\DbQuerySample;
use App\Domain\Model\RequestProfile;
use App\Domain\Model\Snapshot;
use App\Domain\Model\SnapshotId;
use App\Shared\Util\CanonicalJson;

final readonly class ArtifactIngestionService
{
    public function __construct(
        private ArtifactValidationService $validationService,
        private SnapshotStoreInterface $snapshotStore,
        private RetentionManagerInterface $retentionManager,
    ) {
    }

    /**
     * @param list<ArtifactDescriptor> $artifacts
     * @param array<string, scalar|array<array-key, scalar|null>|null> $environmentHints
     * @return array{snapshot: Snapshot|null, validation: list<\App\Domain\Model\ValidationResult>, endpoint_count:int, query_count:int, span_count:int}
     */
    public function ingest(array $artifacts, array $environmentHints = []): array
    {
        $validations = $this->validationService->validateMany($artifacts);
        $hasFailedValidation = false;
        foreach ($validations as $validation) {
            if (!$validation->ok) {
                $hasFailedValidation = true;
                break;
            }
        }

        if ($hasFailedValidation) {
            return [
                'snapshot' => null,
                'validation' => $validations,
                'endpoint_count' => 0,
                'query_count' => 0,
                'span_count' => 0,
            ];
        }

        $parsedArtifacts = [];
        foreach ($validations as $index => $validation) {
            $handler = $this->validationService->resolveParser($validation);
            if ($handler === null) {
                continue;
            }

            $parsedArtifacts[] = $handler->parse($artifacts[$index], $validation);
        }

        $snapshot = $this->buildSnapshot($parsedArtifacts);
        $this->snapshotStore->persist($snapshot, $environmentHints);
        $this->retentionManager->rotate();

        $spanCount = 0;
        foreach ($snapshot->requestProfiles as $profile) {
            $spanCount += count($profile->spans);
        }

        return [
            'snapshot' => $snapshot,
            'validation' => $validations,
            'endpoint_count' => count($snapshot->requestProfiles),
            'query_count' => count($snapshot->dbQuerySamples),
            'span_count' => $spanCount,
        ];
    }

    /**
     * @param list<ParsedArtifact> $parsedArtifacts
     */
    private function buildSnapshot(array $parsedArtifacts): Snapshot
    {
        $sources = [];
        $profiles = [];
        $queries = [];

        foreach ($parsedArtifacts as $parsed) {
            $sources[] = $parsed->source;
            $profiles = array_merge($profiles, $parsed->requestProfiles);
            $queries = array_merge($queries, $parsed->dbQuerySamples);
        }

        $profiles = $this->deduplicateProfiles($profiles);
        $queries = $this->mergeQuerySamples($queries);
        usort($sources, static function ($a, $b): int {
            $cmp = strcmp($a->path, $b->path);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp(CanonicalJson::encode($a->toArray()), CanonicalJson::encode($b->toArray()));
        });
        usort($profiles, static function (RequestProfile $a, RequestProfile $b): int {
            $cmp = strcmp($a->endpoint, $b->endpoint);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp(CanonicalJson::encode($a->toArray()), CanonicalJson::encode($b->toArray()));
        });
        usort($queries, static function (DbQuerySample $a, DbQuerySample $b): int {
            $cmp = strcmp($a->fingerprint, $b->fingerprint);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp(CanonicalJson::encode($a->toArray()), CanonicalJson::encode($b->toArray()));
        });

        $id = $this->buildSnapshotId($sources, $profiles, $queries);

        return new Snapshot(
            id: new SnapshotId($id),
            collectedAt: gmdate(DATE_ATOM),
            sources: $sources,
            requestProfiles: $profiles,
            dbQuerySamples: $queries,
        );
    }

    /**
     * @param list<\App\Domain\Model\SourceArtifact> $sources
     * @param list<RequestProfile> $profiles
     * @param list<DbQuerySample> $queries
     */
    private function buildSnapshotId(array $sources, array $profiles, array $queries): string
    {
        $hashInput = [
            'sources' => array_map(static fn ($source): array => $source->toArray(), $sources),
            'request_profiles' => array_map(static fn (RequestProfile $profile): array => $profile->toArray(), $profiles),
            'db_query_samples' => array_map(static fn (DbQuerySample $query): array => $query->toArray(), $queries),
        ];

        return hash('sha256', CanonicalJson::encode($hashInput));
    }

    /**
     * @param list<RequestProfile> $profiles
     * @return list<RequestProfile>
     */
    private function deduplicateProfiles(array $profiles): array
    {
        $byKey = [];

        foreach ($profiles as $profile) {
            $key = hash('sha256', CanonicalJson::encode($profile->toArray()));
            $byKey[$key] = $profile;
        }

        return array_values($byKey);
    }

    /**
     * @param list<DbQuerySample> $samples
     * @return list<DbQuerySample>
     */
    private function mergeQuerySamples(array $samples): array
    {
        /** @var array<string, array{total:float,count:int,lock:float|null,rows:float|null,examples:list<string>,evidence:list<\App\Domain\Model\EvidenceRef>}> $merged */
        $merged = [];

        foreach ($samples as $sample) {
            $bucket = $merged[$sample->fingerprint] ?? [
                'total' => 0.0,
                'count' => 0,
                'lock' => null,
                'rows' => null,
                'examples' => [],
                'evidence' => [],
            ];

            $bucket['total'] += $sample->totalTimeMs;
            $bucket['count'] += $sample->count;
            $bucket['lock'] = ($bucket['lock'] ?? 0.0) + ($sample->lockMs ?? 0.0);
            $bucket['rows'] = ($bucket['rows'] ?? 0.0) + ($sample->rowsExamined ?? 0.0);
            $bucket['examples'] = array_values(array_unique(array_merge($bucket['examples'], $sample->examples)));
            $bucket['evidence'] = array_merge($bucket['evidence'], $sample->evidence);

            $merged[$sample->fingerprint] = $bucket;
        }

        $result = [];
        foreach ($merged as $fingerprint => $bucket) {
            $avg = $bucket['count'] > 0 ? $bucket['total'] / $bucket['count'] : 0.0;
            $result[] = new DbQuerySample(
                fingerprint: $fingerprint,
                totalTimeMs: round($bucket['total'], 3),
                avgTimeMs: round($avg, 3),
                count: $bucket['count'],
                lockMs: $bucket['lock'] !== null ? round($bucket['lock'], 3) : null,
                rowsExamined: $bucket['rows'] !== null ? round($bucket['rows'], 3) : null,
                examples: array_slice($bucket['examples'], 0, 3),
                evidence: $bucket['evidence'],
            );
        }

        return $result;
    }
}
