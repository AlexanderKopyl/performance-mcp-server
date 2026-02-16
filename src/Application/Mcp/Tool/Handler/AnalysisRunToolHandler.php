<?php

declare(strict_types=1);

namespace App\Application\Mcp\Tool\Handler;

use App\Application\Analysis\Service\AnalysisRunService;
use App\Application\Mcp\Tool\ToolHandlerInterface;
use App\Shared\Error\ErrorCode;
use App\Shared\Error\ErrorEnvelope;
use App\Shared\Mcp\McpRequest;
use App\Shared\Mcp\McpResponse;

final readonly class AnalysisRunToolHandler implements ToolHandlerInterface
{
    public function __construct(private AnalysisRunService $analysisRunService)
    {
    }

    public function toolName(): string
    {
        return 'analysis.run';
    }

    public function handle(McpRequest $request): McpResponse
    {
        $snapshotId = $this->readSnapshotId($request);
        if ($snapshotId === null) {
            return new McpResponse(
                id: $request->id,
                result: null,
                error: new ErrorEnvelope(
                    code: ErrorCode::INVALID_REQUEST,
                    message: 'Expected params.normalized_snapshot_id as non-empty string.',
                    correlationId: $request->correlationId,
                ),
            );
        }

        $result = $this->analysisRunService->run($snapshotId, $request->params);
        if ($result === null) {
            return new McpResponse(
                id: $request->id,
                result: null,
                error: new ErrorEnvelope(
                    code: ErrorCode::INVALID_REQUEST,
                    message: sprintf('Snapshot "%s" was not found.', $snapshotId),
                    correlationId: $request->correlationId,
                ),
            );
        }

        $result['correlation_id'] = $request->correlationId;

        return new McpResponse(
            id: $request->id,
            result: $result,
            error: null,
        );
    }

    private function readSnapshotId(McpRequest $request): ?string
    {
        $snapshotId = $request->params['normalized_snapshot_id'] ?? $request->params['snapshot_id'] ?? null;
        if (!is_string($snapshotId) || trim($snapshotId) === '') {
            return null;
        }

        return trim($snapshotId);
    }
}
