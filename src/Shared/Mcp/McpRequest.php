<?php

declare(strict_types=1);

namespace App\Shared\Mcp;

final readonly class McpRequest
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public int|string|null $id,
        public string $method,
        public array $params,
        public string $correlationId,
    ) {
    }
}
