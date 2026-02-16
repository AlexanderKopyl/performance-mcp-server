<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Shared\Error\ErrorCode;
use App\Shared\Error\ErrorEnvelope;
use App\Shared\Mcp\McpRequest;
use JsonException;

final readonly class McpRequestDeserializer
{
    public function __construct(private CorrelationIdFactory $correlationIdFactory)
    {
    }

    public function deserialize(string $rawMessage): DeserializationResult
    {
        try {
            /** @var mixed $payload */
            $payload = json_decode($rawMessage, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $correlationId = substr(hash('sha256', $rawMessage), 0, 32);

            return DeserializationResult::failure(new ErrorEnvelope(
                code: ErrorCode::INVALID_REQUEST,
                message: 'Malformed JSON payload.',
                correlationId: $correlationId,
                details: ['reason' => $exception->getMessage()],
            ));
        }

        if (!is_array($payload) || array_is_list($payload)) {
            $correlationId = substr(hash('sha256', $rawMessage), 0, 32);

            return DeserializationResult::failure(new ErrorEnvelope(
                code: ErrorCode::INVALID_REQUEST,
                message: 'Request payload must be a JSON object.',
                correlationId: $correlationId,
            ));
        }

        $correlationId = $this->correlationIdFactory->fromPayload($payload, $rawMessage);
        $requestId = $payload['id'] ?? null;

        if ($requestId !== null && !is_int($requestId) && !is_string($requestId)) {
            return DeserializationResult::failure(new ErrorEnvelope(
                code: ErrorCode::INVALID_REQUEST,
                message: 'Request id must be string, integer, or null.',
                correlationId: $correlationId,
            ));
        }

        $method = $payload['method'] ?? null;
        if (!is_string($method) || $method === '') {
            return DeserializationResult::failure(new ErrorEnvelope(
                code: ErrorCode::INVALID_REQUEST,
                message: 'Request method must be a non-empty string.',
                correlationId: $correlationId,
            ), $requestId);
        }

        $params = $payload['params'] ?? [];
        if (!is_array($params) || array_is_list($params)) {
            return DeserializationResult::failure(new ErrorEnvelope(
                code: ErrorCode::INVALID_REQUEST,
                message: 'Request params must be an object.',
                correlationId: $correlationId,
            ), $requestId);
        }

        return DeserializationResult::success(new McpRequest(
            id: $requestId,
            method: $method,
            params: $params,
            correlationId: $correlationId,
        ));
    }
}
