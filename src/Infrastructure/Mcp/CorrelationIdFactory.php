<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Shared\Util\CanonicalJson;

final class CorrelationIdFactory
{
    /**
     * @param array<string, mixed> $payload
     */
    public function fromPayload(array $payload, string $rawMessage): string
    {
        if (isset($payload['params']) && is_array($payload['params'])) {
            $candidate = $payload['params']['correlation_id'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        $basis = [
            'id' => $payload['id'] ?? null,
            'method' => $payload['method'] ?? null,
            'params' => $payload['params'] ?? null,
            'raw' => $rawMessage,
        ];

        return substr(hash('sha256', CanonicalJson::encode($basis)), 0, 32);
    }
}
