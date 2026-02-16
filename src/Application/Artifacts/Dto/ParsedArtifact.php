<?php

declare(strict_types=1);

namespace App\Application\Artifacts\Dto;

use App\Domain\Model\DbQuerySample;
use App\Domain\Model\RequestProfile;
use App\Domain\Model\SourceArtifact;

final readonly class ParsedArtifact
{
    /**
     * @param list<RequestProfile> $requestProfiles
     * @param list<DbQuerySample> $dbQuerySamples
     */
    public function __construct(
        public SourceArtifact $source,
        public array $requestProfiles = [],
        public array $dbQuerySamples = [],
    ) {
    }
}
