<?php

declare(strict_types=1);

namespace App\Shared\Error;

final readonly class ErrorEnvelope
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public ErrorCode $code,
        public string $message,
        public string $correlationId,
        public array $details = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'message' => $this->message,
            'correlation_id' => $this->correlationId,
            'details' => $this->details,
        ];
    }
}
