<?php

declare(strict_types=1);

namespace App\Application\Mcp\Tool\Handler;

use App\Application\Artifacts\Service\ArtifactIngestionService;
use App\Application\Mcp\Tool\ToolHandlerInterface;
use App\Domain\Model\ArtifactDescriptor;
use App\Shared\Error\ErrorCode;
use App\Shared\Error\ErrorEnvelope;
use App\Shared\Mcp\McpRequest;
use App\Shared\Mcp\McpResponse;

final readonly class ArtifactsIngestToolHandler implements ToolHandlerInterface
{
    public function __construct(private ArtifactIngestionService $ingestionService)
    {
    }

    public function toolName(): string
    {
        return 'artifacts.ingest';
    }

    public function handle(McpRequest $request): McpResponse
    {
        $artifacts = $this->readArtifactDescriptors($request);
        if ($artifacts === null) {
            return new McpResponse(
                id: $request->id,
                result: null,
                error: new ErrorEnvelope(
                    code: ErrorCode::INVALID_REQUEST,
                    message: 'Expected params.artifacts as a non-empty list of artifact descriptors with path.',
                    correlationId: $request->correlationId,
                ),
            );
        }

        $environmentHints = is_array($request->params['environment_hints'] ?? null)
            ? $request->params['environment_hints']
            : [];

        $ingested = $this->ingestionService->ingest($artifacts, $environmentHints);

        $failed = array_values(array_filter(
            $ingested['validation'],
            static fn ($result): bool => !$result->ok,
        ));

        if ($failed !== []) {
            return new McpResponse(
                id: $request->id,
                result: [
                    'results' => array_map(static fn ($result): array => $result->toArray(), $ingested['validation']),
                ],
                error: new ErrorEnvelope(
                    code: ErrorCode::INVALID_REQUEST,
                    message: 'One or more artifacts failed strict validation.',
                    correlationId: $request->correlationId,
                ),
            );
        }

        $snapshot = $ingested['snapshot'];
        if ($snapshot === null) {
            return new McpResponse(
                id: $request->id,
                result: null,
                error: new ErrorEnvelope(
                    code: ErrorCode::INTERNAL_ERROR,
                    message: 'Snapshot was not generated for ingest request.',
                    correlationId: $request->correlationId,
                ),
            );
        }

        return new McpResponse(
            id: $request->id,
            result: [
                'normalized_snapshot_id' => $snapshot->id->value,
                'counts' => [
                    'endpoints' => $ingested['endpoint_count'],
                    'queries' => $ingested['query_count'],
                    'spans' => $ingested['span_count'],
                ],
                'sources' => array_map(static fn ($source): array => $source->toArray(), $snapshot->sources),
            ],
            error: null,
        );
    }

    /**
     * @return list<ArtifactDescriptor>|null
     */
    private function readArtifactDescriptors(McpRequest $request): ?array
    {
        $raw = $request->params['artifacts'] ?? null;
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        $result = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null)) {
                return null;
            }

            $hints = is_array($entry['hints'] ?? null) ? $entry['hints'] : [];
            $result[] = new ArtifactDescriptor(path: $entry['path'], hints: $hints);
        }

        return $result;
    }
}
