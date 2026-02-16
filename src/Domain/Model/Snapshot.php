<?php

declare(strict_types=1);

namespace App\Domain\Model;

final readonly class Snapshot
{
    /**
     * @param list<SourceArtifact> $sources
     * @param list<RequestProfile> $requestProfiles
     * @param list<DbQuerySample> $dbQuerySamples
     */
    public function __construct(
        public SnapshotId $id,
        public string $collectedAt,
        public array $sources,
        public array $requestProfiles,
        public array $dbQuerySamples,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'collected_at' => $this->collectedAt,
            'sources' => array_map(static fn (SourceArtifact $source): array => $source->toArray(), $this->sources),
            'request_profiles' => array_map(static fn (RequestProfile $profile): array => $profile->toArray(), $this->requestProfiles),
            'db_query_samples' => array_map(static fn (DbQuerySample $sample): array => $sample->toArray(), $this->dbQuerySamples),
        ];
    }
}
