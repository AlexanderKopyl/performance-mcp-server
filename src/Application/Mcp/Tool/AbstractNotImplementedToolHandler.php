<?php

declare(strict_types=1);

namespace App\Application\Mcp\Tool;

use App\Shared\Error\ErrorCode;
use App\Shared\Error\ErrorEnvelope;
use App\Shared\Mcp\McpRequest;
use App\Shared\Mcp\McpResponse;

abstract class AbstractNotImplementedToolHandler implements ToolHandlerInterface
{
    abstract protected function message(): string;

    public function handle(McpRequest $request): McpResponse
    {
        return new McpResponse(
            id: $request->id,
            result: null,
            error: new ErrorEnvelope(
                code: ErrorCode::NOT_IMPLEMENTED,
                message: $this->message(),
                correlationId: $request->correlationId,
                details: ['tool' => $this->toolName()],
            ),
        );
    }
}
