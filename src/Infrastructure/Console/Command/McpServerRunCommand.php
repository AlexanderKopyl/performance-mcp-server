<?php

declare(strict_types=1);

namespace App\Infrastructure\Console\Command;

use App\Application\Mcp\Tool\ToolRouter;
use App\Infrastructure\Mcp\McpRequestDeserializer;
use App\Infrastructure\Observability\LogRedactor;
use App\Infrastructure\Storage\FilesystemStorageScaffold;
use App\Shared\Error\ErrorCode;
use App\Shared\Error\ErrorEnvelope;
use App\Shared\Mcp\McpResponse;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

#[AsCommand(name: 'app:mcp:server:run', description: 'Runs the MCP STDIO server loop.')]
final class McpServerRunCommand extends Command
{
    public function __construct(
        private readonly McpRequestDeserializer $deserializer,
        private readonly ToolRouter $toolRouter,
        private readonly LogRedactor $logRedactor,
        private readonly FilesystemStorageScaffold $storageScaffold,
        #[Autowire(service: 'monolog.logger.mcp')]
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->storageScaffold->ensureInitialized();

        $stdin = fopen('php://stdin', 'rb');
        $stdout = fopen('php://stdout', 'wb');

        if ($stdin === false || $stdout === false) {
            return Command::FAILURE;
        }

        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $startedAt = hrtime(true);
            $deserialized = $this->deserializer->deserialize($line);

            if ($deserialized->request === null && $deserialized->error === null) {
                continue;
            }

            if ($deserialized->error instanceof ErrorEnvelope) {
                $this->log('warning', [
                    'tool_name' => 'n/a',
                    'correlation_id' => $deserialized->correlationId,
                    'snapshot_id' => null,
                    'duration_ms' => $this->elapsedMs($startedAt),
                    'error_code' => $deserialized->error->code->value,
                ]);

                $this->writeResponse(
                    $stdout,
                    new McpResponse(
                        id: $deserialized->requestId,
                        result: null,
                        error: $deserialized->error,
                    ),
                );
                continue;
            }

            $request = $deserialized->request;
            $handler = $this->toolRouter->resolve($request->method);

            if ($handler === null) {
                $error = new ErrorEnvelope(
                    code: ErrorCode::METHOD_NOT_FOUND,
                    message: sprintf('Tool "%s" is not registered.', $request->method),
                    correlationId: $request->correlationId,
                    details: ['tool' => $request->method],
                );

                $this->log('warning', [
                    'tool_name' => $request->method,
                    'correlation_id' => $request->correlationId,
                    'snapshot_id' => null,
                    'duration_ms' => $this->elapsedMs($startedAt),
                    'error_code' => $error->code->value,
                ]);

                $this->writeResponse($stdout, new McpResponse($request->id, null, $error));
                continue;
            }

            try {
                $response = $handler->handle($request);
            } catch (Throwable $throwable) {
                $response = new McpResponse(
                    id: $request->id,
                    result: null,
                    error: new ErrorEnvelope(
                        code: ErrorCode::INTERNAL_ERROR,
                        message: 'Unhandled tool execution error.',
                        correlationId: $request->correlationId,
                        details: ['exception' => $throwable::class],
                    ),
                );
            }

            $errorCode = $response->error?->code->value;
            $this->log($errorCode === null ? 'info' : 'error', [
                'tool_name' => $request->method,
                'correlation_id' => $request->correlationId,
                'snapshot_id' => $request->params['snapshot_id'] ?? $request->params['normalized_snapshot_id'] ?? null,
                'duration_ms' => $this->elapsedMs($startedAt),
                'error_code' => $errorCode,
            ]);

            $this->writeResponse($stdout, $response);
        }

        fclose($stdin);
        fclose($stdout);

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, array $context): void
    {
        $baseContext = [
            'timestamp' => gmdate(DATE_ATOM),
            'level' => strtoupper($level),
        ];

        $context = $this->logRedactor->redact(array_merge($baseContext, $context));

        $this->logger->log($level, 'mcp_request', $context);
    }

    private function writeResponse($stdout, McpResponse $response): void
    {
        try {
            $encoded = json_encode($response->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        fwrite($stdout, $encoded."\n");
        fflush($stdout);
    }

    private function elapsedMs(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }
}
