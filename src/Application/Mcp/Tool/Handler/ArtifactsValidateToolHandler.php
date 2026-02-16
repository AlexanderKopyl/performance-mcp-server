<?php

declare(strict_types=1);

namespace App\Application\Mcp\Tool\Handler;

use App\Application\Artifacts\Service\ArtifactValidationService;
use App\Application\Mcp\Tool\ToolHandlerInterface;
use App\Domain\Model\ArtifactDescriptor;
use App\Shared\Error\ErrorCode;
use App\Shared\Error\ErrorEnvelope;
use App\Shared\Mcp\McpRequest;
use App\Shared\Mcp\McpResponse;

final readonly class ArtifactsValidateToolHandler implements ToolHandlerInterface
{
    public function __construct(private ArtifactValidationService $validationService)
    {
    }

    public function toolName(): string
    {
        return 'artifacts.validate';
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

        $validation = $this->validationService->validateMany($artifacts);

        $okCount = 0;
        foreach ($validation as $item) {
            if ($item->ok) {
                ++$okCount;
            }
        }

        return new McpResponse(
            id: $request->id,
            result: [
                'results' => array_map(static fn ($result): array => $result->toArray(), $validation),
                'counts' => [
                    'ok' => $okCount,
                    'failed' => count($validation) - $okCount,
                ],
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
