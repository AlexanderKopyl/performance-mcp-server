<?php

declare(strict_types=1);

namespace App\Application\Mcp\Tool\Handler;

use App\Application\Collector\Service\CollectorRunService;
use App\Application\Mcp\Tool\ToolHandlerInterface;
use App\Shared\Error\ErrorCode;
use App\Shared\Error\ErrorEnvelope;
use App\Shared\Mcp\McpRequest;
use App\Shared\Mcp\McpResponse;
use InvalidArgumentException;
use RuntimeException;

final readonly class CollectRunToolHandler implements ToolHandlerInterface
{
    public function __construct(private CollectorRunService $collectorRunService)
    {
    }

    public function toolName(): string
    {
        return 'collect.run';
    }

    public function handle(McpRequest $request): McpResponse
    {
        try {
            $result = $this->collectorRunService->run($request->params, $request->correlationId);
        } catch (InvalidArgumentException $exception) {
            return new McpResponse(
                id: $request->id,
                result: null,
                error: new ErrorEnvelope(
                    code: ErrorCode::INVALID_REQUEST,
                    message: $exception->getMessage(),
                    correlationId: $request->correlationId,
                ),
            );
        } catch (RuntimeException $exception) {
            return new McpResponse(
                id: $request->id,
                result: null,
                error: new ErrorEnvelope(
                    code: ErrorCode::INTERNAL_ERROR,
                    message: $exception->getMessage(),
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
}
