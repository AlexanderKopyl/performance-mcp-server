<?php

declare(strict_types=1);

namespace App\Shared\Mcp;

use App\Shared\Error\ErrorEnvelope;

final readonly class McpResponse
{
    /**
     * @param array<string, mixed>|null $result
     */
    public function __construct(
        public int|string|null $id,
        public ?array $result,
        public ?ErrorEnvelope $error,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => $this->id,
        ];

        if ($this->error instanceof ErrorEnvelope) {
            $payload['error'] = $this->error->toArray();

            return $payload;
        }

        $payload['result'] = $this->result ?? [];

        return $payload;
    }
}
