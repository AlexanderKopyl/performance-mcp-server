<?php

declare(strict_types=1);

namespace App\Application\Mcp\Tool;

use App\Shared\Mcp\McpRequest;
use App\Shared\Mcp\McpResponse;

interface ToolHandlerInterface
{
    public function toolName(): string;

    public function handle(McpRequest $request): McpResponse;
}
