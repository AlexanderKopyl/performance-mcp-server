<?php

declare(strict_types=1);

namespace App\Application\Mcp\Tool;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class ToolRouter
{
    /** @var array<string, ToolHandlerInterface> */
    private array $handlers = [];

    /**
     * @param iterable<ToolHandlerInterface> $handlers
     */
    public function __construct(
        #[AutowireIterator('app.mcp.tool_handler')]
        iterable $handlers,
    )
    {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->toolName()] = $handler;
        }
    }

    public function resolve(string $toolName): ?ToolHandlerInterface
    {
        return $this->handlers[$toolName] ?? null;
    }
}
