<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Shared\Error\ErrorEnvelope;
use App\Shared\Mcp\McpRequest;

final readonly class DeserializationResult
{
    private function __construct(
        public ?McpRequest $request,
        public ?ErrorEnvelope $error,
        public int|string|null $requestId,
        public string $correlationId,
    ) {
    }

    public static function success(McpRequest $request): self
    {
        return new self(
            request: $request,
            error: null,
            requestId: $request->id,
            correlationId: $request->correlationId,
        );
    }

    public static function failure(ErrorEnvelope $error, int|string|null $requestId = null): self
    {
        return new self(
            request: null,
            error: $error,
            requestId: $requestId,
            correlationId: $error->correlationId,
        );
    }
}
